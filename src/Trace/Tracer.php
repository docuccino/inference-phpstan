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
 * Drives the interprocedural, bounded, memoised, cycle-guarded walk behind
 * {@see TypeEngine::trace()} (design §4, Spike B). The
 * visitor supplies pure semantics + harvesting; the Tracer owns everything the
 * visitor cannot: depth, per-`class::method` memoisation, the cycle guard,
 * callee resolution, per-file parser priming (via the adapter), and — crucially
 * for determinism — descent ordering.
 *
 * `enterNode` returning `true` is a *request* the Tracer may decline: it only
 * descends into project-code callees within depth and file budget. A visitor
 * implementing {@see FollowsReturnType} widens this to non-vendor app callees
 * OUTSIDE the project paths whose RETURN TYPE it follows (the modular
 * `$query->query()` Query-Builder hop) — never into vendor.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class Tracer
{
    /** @var array<string, true> memoised class::method */
    private array $visited = [];

    /** @var array<string, true> every file the walk located/analysed */
    private array $visitedFiles = [];

    /** The normalised app vendor directory (follow-beyond never descends into it), or null when unset. */
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
            // Confine the walk to the target class method. Matching on both the
            // class and function name also excludes closures for free (their
            // function name won't match) — no manual method stack needed.
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

        // Deterministic descent order: by source position (PHPStan's callback
        // order for a chained expression is NOT left-to-right); first-seen wins
        // (Spike B trap #5). Collect-then-recurse — never nest processNodes.
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
     * Whether to descend into a callee that lies OUTSIDE the configured project paths: only when the
     * visitor follows the callee's resolved return type (a Query-Builder visitor following a Spatie
     * `QueryBuilder` subclass into a modular Queries class) AND the callee is not vendor code. Bounded
     * like every descent by depth/file budget; vendor is never followed.
     */
    private function shouldFollowBeyondProject(Node\Expr $node, Callee $callee, TypeScopeImpl $typeScope): bool
    {
        if (! $this->visitor instanceof FollowsReturnType || $this->isVendorFile($callee->file)) {
            return false;
        }

        return $this->visitor->followsReturnType($typeScope->typeOf($node));
    }

    /**
     * Whether a file is under the app's vendor tree. With no vendor boundary configured, everything
     * outside the project paths is treated as vendor (follow-beyond stays off — the safe default).
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
