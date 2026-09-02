<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\FoldsCallReturns;
use Docuccino\Core\Inference\FollowsReturnType;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Support\SourceOrder;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use Throwable;

/**
 * Drives the interprocedural, bounded, memoised, cycle-guarded walk behind {@see TypeEngine::trace()}. The
 * visitor is pure semantics and harvesting; the Tracer owns what the visitor can't see — depth,
 * per-`class::method` memoisation, the cycle guard, callee resolution, per-file parser priming, and descent
 * ordering (which is what makes the walk deterministic).
 *
 * `enterNode` returning `true` is a request the Tracer may decline: it descends only into project callees
 * within depth and file budget. A visitor implementing {@see FollowsReturnType} widens that to non-vendor
 * app callees outside the project paths whose return type it follows (the modular `$query->query()` hop) —
 * never into vendor.
 *
 * A visitor may also ask, through {@see FoldsCallReturns}, for the VALUE a call returns; that needs another
 * file analysed, so the request is queued and {@see ReturnValueFolder} answers it once the walk returns —
 * the same collect-then-recurse discipline the descent uses.
 *
 * The per-file walk comes from {@see FileWalks}, which is why tracing a route the analysis already looked at
 * costs no second pass over its controller.
 *
 * File accounting is {@see TraceFiles}: what the walk may still afford to open is a separate question from
 * what the fragment it feeds invalidates on, so a body written in a trait is recorded without ever costing
 * the traversal a slot.
 *
 * @internal
 */
final class Tracer
{
    /** @var array<string, true> memoised class::method */
    private array $visited = [];

    /** Every file the walk read from, and the budget it spends opening them ({@see TraceFiles}). */
    private readonly TraceFiles $files;

    /**
     * Return folds requested during the current method's walk, drained by {@see foldPending()}.
     *
     * @var list<array{callee: Callee, positional: list<ConstValue>, named: array<string, ConstValue>, onFolded: callable(?ConstValue, ?Node\Expr): void}>
     */
    private array $pending = [];

    /** The normalised app vendor directory, never descended into; null when unset. */
    private readonly ?string $vendorPrefix;

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly FileWalks $walks,
        private readonly TypeTranslator $translator,
        private readonly ProjectFilter $projectFilter,
        private readonly CalleeResolver $calleeResolver,
        private readonly ReturnValueFolder $returnFolder,
        private readonly TraceVisitor $visitor,
        private readonly int $maxDepth = 4,
        int $fileBudget = 40,
        ?string $vendorPath = null,
    ) {
        $this->files = new TraceFiles($fileBudget);
        $this->vendorPrefix = $vendorPath === null ? null : rtrim($adapter->normalize($vendorPath), '/');
    }

    public function run(string $class, string $method, string $file, int $depth = 0): void
    {
        $this->enter($this->calleeResolver->root($class, $method, $file), $depth);
    }

    /**
     * Walk one method, then descend. The file recorded is the one the body is WRITTEN in as well as the
     * one the walk opens: PHP inlines a trait's method into the using class's file, so the entries a
     * trait's body carries are harvested from a file no fragment would otherwise depend on
     * ({@see TraceFiles::depend()}).
     */
    private function enter(Callee $callee, int $depth): void
    {
        $key = $callee->key();
        if ($depth > $this->maxDepth || isset($this->visited[$key]) || ! $this->files->admit($callee->file)) {
            return;
        }
        $this->visited[$key] = true;
        $this->files->depend($callee->writtenIn());

        /** @var list<array{callee: Callee, pos: int}> $descend */
        $descend = [];

        try {
            $this->walks->walk($callee->file, function (Node $node, Scope $scope) use ($callee, $depth, &$descend): void {
                // Confine the walk to the target method. Matching class + function name also excludes
                // closures for free (their function name won't match) — no method stack needed.
                if ($scope->getClassReflection()?->getName() !== $callee->class
                    || $scope->getFunction()?->getName() !== $callee->method
                ) {
                    return;
                }

                $typeScope = new TypeScopeImpl(
                    $scope,
                    $this->translator,
                    fn (Node\Expr $call, callable $onFolded): bool => $this->queueFold($call, $scope, $onFolded, $depth),
                );
                $descendRequested = $this->visitor->enterNode($node, $typeScope);

                if (! $descendRequested) {
                    return;
                }
                if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\StaticCall) {
                    return;
                }

                $resolved = $this->calleeResolver->resolve($node, $scope);
                if ($resolved === null) {
                    return; // magic / unresolvable / PHP-internal — the engine declines
                }
                if (! $this->projectFilter->isProjectFile($resolved->file)
                    && ! $this->shouldFollowBeyondProject($node, $resolved, $scope)
                ) {
                    return; // vendor, or outside project paths with no return-type follow — declined
                }

                $descend[] = ['callee' => $resolved, 'pos' => SourceOrder::of($node)];
            });
        } finally {
            // Every queued fold is answered, even when the walk blew up — a visitor that reserved a slot
            // for the answer is owed one.
            $this->foldPending();
        }

        // Order by source position — PHPStan's callback order for a chained expression is not
        // left-to-right, and {@see SourceOrder} is what makes a chain's links order by the name they are
        // written with rather than by the receiver offset they share — and let first-seen win. Collect
        // then recurse; never nest processNodes.
        usort($descend, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $seen = [];
        foreach ($descend as $target) {
            $ck = $target['callee']->key();
            if (isset($seen[$ck])) {
                continue;
            }
            $seen[$ck] = true;
            $this->enter($target['callee'], $depth + 1);
        }
    }

    /**
     * Queue a return fold, gated by {@see canFoldCallee()}. The call site's arguments are folded HERE, in the
     * walk, because answering the fold means analysing another file and that must not nest `processNodes`.
     *
     * @param  callable(?ConstValue, ?Node\Expr): void  $onFolded
     */
    private function queueFold(Node\Expr $call, Scope $scope, callable $onFolded, int $depth): bool
    {
        if ($depth >= $this->maxDepth) {
            return false;
        }
        if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
            return false;
        }
        if ($call->isFirstClassCallable()) {
            return false; // a callable, not a value the code ever asks for
        }

        $callee = $this->calleeResolver->resolve($call, $scope);
        if ($callee === null) {
            return false; // magic / unresolvable / PHP-internal
        }
        if (! $this->canFoldCallee($call, $callee, $scope)) {
            return false;
        }

        $positional = [];
        $named = [];
        foreach ($call->getArgs() as $arg) {
            if ($arg->unpack) {
                return false; // a spread breaks positional binding — decline rather than mis-bind
            }
            $value = ConstantFolder::fold($arg->value, $scope) ?? ConstValue::unknown('non-constant call argument');
            if ($arg->name instanceof Node\Identifier) {
                $named[$arg->name->toString()] = $value;
            } else {
                $positional[] = $value;
            }
        }

        $this->pending[] = ['callee' => $callee, 'positional' => $positional, 'named' => $named, 'onFolded' => $onFolded];

        return true;
    }

    /**
     * Where a fold may read: wherever a descent may go, plus a non-vendor helper in the file being walked —
     * that file is already in this analysis, so its own methods cost nothing new to open.
     */
    private function canFoldCallee(Node\Expr $call, Callee $callee, Scope $scope): bool
    {
        if ($this->projectFilter->isProjectFile($callee->file)) {
            return true;
        }

        if (! $this->isVendorFile($callee->file)
            && $this->adapter->normalize($callee->file) === $this->adapter->normalize($scope->getFile())
        ) {
            return true;
        }

        return $this->shouldFollowBeyondProject($call, $callee, $scope);
    }

    /**
     * Answer this walk's queued folds, in the order they were requested — PHPStan's node-callback order,
     * which is not the order the calls are written. Unlike the descent that is not an ordering the output
     * can see: a visitor reserves its entry's position BEFORE asking, so the document reads in walk order
     * whichever fold answers first, and every fold in the corpus resolves into a file the walk already
     * opened, so none of them compete for the budget either. Ordering them by source position would be a
     * mechanism with no measured population; it becomes one the day a fold reaches a file of its own.
     * No visitor runs during a fold, so the queue cannot grow while it drains. The callee's file is charged
     * the budget like any other.
     */
    private function foldPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $request) {
            $folded = null;
            try {
                if ($this->files->admit($request['callee']->file)) {
                    // The value folded is written in the body, which a trait puts in another file.
                    $this->files->depend($request['callee']->writtenIn());
                    $folded = $this->returnFolder->fold($request['callee'], $request['positional'], $request['named']);
                }
            } catch (Throwable) {
                // Best-effort, like the walk itself: the visitor hears "nothing folded" and degrades.
            }

            try {
                ($request['onFolded'])($folded?->value, $folded?->expr);
            } catch (Throwable) {
                // A visitor's own failure must not abandon the rest of the queue.
            }
        }
    }

    /**
     * Descend into a callee outside the configured project paths only when the visitor follows its resolved
     * return type (a Query-Builder visitor following a Spatie `QueryBuilder` subclass into a modular
     * Queries class) and the callee isn't vendor. Still bounded by depth/file budget.
     */
    private function shouldFollowBeyondProject(Node\Expr $node, Callee $callee, Scope $scope): bool
    {
        if (! $this->visitor instanceof FollowsReturnType || $this->isVendorFile($callee->file)) {
            return false;
        }

        return $this->visitor->followsReturnType($this->translator->translate($scope->getType($node)));
    }

    /**
     * With no vendor boundary configured, everything outside the project paths counts as vendor, so
     * follow-beyond stays off — the safe default.
     */
    private function isVendorFile(string $file): bool
    {
        if ($this->vendorPrefix === null) {
            return true;
        }

        $normalised = $this->adapter->normalize($file);

        return $normalised === $this->vendorPrefix || str_starts_with($normalised, $this->vendorPrefix.'/');
    }

    /**
     * @return list<string>
     */
    public function visitedFiles(): array
    {
        return $this->files->all();
    }
}
