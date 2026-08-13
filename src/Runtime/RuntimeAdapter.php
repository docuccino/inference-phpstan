<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

/**
 * The single seam onto PHPStan's non-BC-covered plumbing (`ContainerFactory`, `NodeScopeResolver`, the
 * parser router, `FileHelper`), with one implementation per supported PHPStan minor. The BC-stable surfaces
 * — `Scope`, `Type`, throw points, dynamic-return-type extensions — are used directly by the translator,
 * throw analyzer and tracer, not confined here.
 *
 * Upward, an adapter speaks only `PhpParser\Node`, `Scope` and `ReflectionProvider`, all BC-promised, so a
 * new minor means revisiting booting, parser priming and file walking and nothing else.
 *
 * @internal
 */
interface RuntimeAdapter
{
    /**
     * Build the container, run Larastan's bootstrap files (which boot the host app), and prime the project's
     * sources. Idempotent.
     *
     * @throws BootFailedException when the container or Larastan bootstrap fails
     */
    public function boot(): void;

    /**
     * Add files to the analysed set on BOTH the `NodeScopeResolver` and the `pathRoutingParser`, additively.
     * PHPStan strips unprimed bodies — a file missing from the parser router's set goes to `CleaningParser`
     * and its returns and throw points vanish silently — so prime every file, entry action or descent
     * target, before its first parse.
     *
     * @param  list<string>  $files  raw (un-normalised) paths
     */
    public function prime(array $files): void;

    /**
     * Parse a file and drive `NodeScopeResolver::processNodes` over it, calling
     * `$callback(PhpParser\Node $node, PHPStan\Analyser\Scope $scope): void` per node. Primes the file first.
     */
    public function processFile(string $file, callable $callback): void;

    /** Normalise a path exactly the way PHPStan's parser router does. */
    public function normalize(string $file): string;

    /**
     * Make a scope safe to query after its walk has finished. Since 2.2 the scope handed to a `processFile`
     * callback resolves expressions by suspending its fiber, so it only answers while that fiber is alive —
     * retaining one (as harvesting a `MethodReturnStatementsNode` does) and calling `getType()` later throws
     * "Cannot suspend outside of a fiber". Call this on any scope that outlives the callback that produced it.
     */
    public function stableScope(Scope $scope): Scope;

    /** The container's reflection provider (autoloader-backed, lazy). */
    public function reflectionProvider(): ReflectionProvider;
}
