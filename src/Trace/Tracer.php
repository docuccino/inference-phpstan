<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\FollowsReturnType;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;

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
 * @internal
 */
final class Tracer
{
    /** @var array<string, true> memoised class::method */
    private array $visited = [];

    /** @var array<string, true> every file the walk located/analysed */
    private array $visitedFiles = [];

    /** The normalised app vendor directory, never descended into; null when unset. */
    private readonly ?string $vendorPrefix;

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly TypeTranslator $translator,
        private readonly ProjectFilter $projectFilter,
        private readonly CalleeResolver $calleeResolver,
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
        if ($depth > $this->maxDepth || isset($this->visited[$key])) {
            return;
        }
        if (count($this->visitedFiles) >= $this->fileBudget && ! isset($this->visitedFiles[$file])) {
            return;
        }
        $this->visited[$key] = true;
        $this->visitedFiles[$file] = true;

        /** @var list<array{callee: Callee, pos: int}> $descend */
        $descend = [];

        $this->adapter->processFile($file, function (Node $node, Scope $scope) use ($class, $method, &$descend): void {
            // Confine the walk to the target method. Matching class + function name also excludes
            // closures for free (their function name won't match) — no method stack needed.
            if ($scope->getClassReflection()?->getName() !== $class
                || $scope->getFunction()?->getName() !== $method
            ) {
                return;
            }

            $typeScope = new TypeScopeImpl($scope, $this->translator);
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
                && ! $this->shouldFollowBeyondProject($node, $callee, $typeScope)
            ) {
                return; // vendor, or outside project paths with no return-type follow — declined
            }

            $pos = $node->getStartFilePos();
            $descend[] = ['callee' => $callee, 'pos' => $pos < 0 ? PHP_INT_MAX : $pos];
        });

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
     * Descend into a callee outside the configured project paths only when the visitor follows its resolved
     * return type (a Query-Builder visitor following a Spatie `QueryBuilder` subclass into a modular
     * Queries class) and the callee isn't vendor. Still bounded by depth/file budget.
     */
    private function shouldFollowBeyondProject(Node\Expr $node, Callee $callee, TypeScopeImpl $typeScope): bool
    {
        if (! $this->visitor instanceof FollowsReturnType || $this->isVendorFile($callee->file)) {
            return false;
        }

        return $this->visitor->followsReturnType($typeScope->typeOf($node));
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
