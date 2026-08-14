<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\FoldsCallReturns;
use Docuccino\Core\Inference\FollowsReturnType;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
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
 * @internal
 */
final class Tracer
{
    /** @var array<string, true> memoised class::method */
    private array $visited = [];

    /** @var array<string, true> every file the walk located/analysed */
    private array $visitedFiles = [];

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
        private readonly TypeTranslator $translator,
        private readonly ProjectFilter $projectFilter,
        private readonly CalleeResolver $calleeResolver,
        private readonly ReturnValueFolder $returnFolder,
        private readonly TraceVisitor $visitor,
        private readonly int $maxDepth = 4,
        private readonly int $fileBudget = 40,
        ?string $vendorPath = null,
    ) {
        $this->vendorPrefix = $vendorPath === null ? null : rtrim($adapter->normalize($vendorPath), '/');
    }

    public function run(string $class, string $method, string $file, int $depth = 0): void
    {
        $key = $class.'::'.$method;
        if ($depth > $this->maxDepth || isset($this->visited[$key]) || ! $this->admitFile($file)) {
            return;
        }
        $this->visited[$key] = true;

        /** @var list<array{callee: Callee, pos: int}> $descend */
        $descend = [];

        try {
            $this->adapter->processFile($file, function (Node $node, Scope $scope) use ($class, $method, $depth, &$descend): void {
                // Confine the walk to the target method. Matching class + function name also excludes
                // closures for free (their function name won't match) — no method stack needed.
                if ($scope->getClassReflection()?->getName() !== $class
                    || $scope->getFunction()?->getName() !== $method
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

                $callee = $this->calleeResolver->resolve($node, $scope);
                if ($callee === null) {
                    return; // magic / unresolvable / PHP-internal — the engine declines
                }
                if (! $this->projectFilter->isProjectFile($callee->file)
                    && ! $this->shouldFollowBeyondProject($node, $callee, $scope)
                ) {
                    return; // vendor, or outside project paths with no return-type follow — declined
                }

                $pos = $node->getStartFilePos();
                $descend[] = ['callee' => $callee, 'pos' => $pos < 0 ? PHP_INT_MAX : $pos];
            });
        } finally {
            // Every queued fold is answered, even when the walk blew up — a visitor that reserved a slot
            // for the answer is owed one.
            $this->foldPending();
        }

        // Order by source position — PHPStan's callback order for a chained expression is not
        // left-to-right — and let first-seen win. Collect then recurse; never nest processNodes.
        usort($descend, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $seen = [];
        foreach ($descend as $target) {
            $ck = $target['callee']->key();
            if (isset($seen[$ck])) {
                continue;
            }
            $seen[$ck] = true;
            $this->run($target['callee']->class, $target['callee']->method, $target['callee']->file, $depth + 1);
        }
    }

    /**
     * Queue a return fold, gated by {@see canFoldCallee()}. The call site's arguments are folded HERE, on
     * the live scope, so nothing of PHPStan's has to be retained until the fold runs.
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
     * Answer this walk's queued folds, in the order they were requested. No visitor runs during a fold, so
     * the queue cannot grow while it drains. The callee's file goes through {@see admitFile()} like any other.
     */
    private function foldPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $request) {
            $folded = null;
            try {
                if ($this->admitFile($request['callee']->file)) {
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
     * Admits a file to the trace's file set — which is what reports it as a dependency, keeping the fragment
     * cache sound — unless that would exceed the per-analysis budget. An already-admitted file always passes.
     */
    private function admitFile(string $file): bool
    {
        if (isset($this->visitedFiles[$file])) {
            return true;
        }
        if (count($this->visitedFiles) >= $this->fileBudget) {
            return false;
        }
        $this->visitedFiles[$file] = true;

        return true;
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
        return array_keys($this->visitedFiles);
    }
}
