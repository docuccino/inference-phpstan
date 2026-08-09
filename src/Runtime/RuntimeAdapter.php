<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use PHPStan\Reflection\ReflectionProvider;

/**
 * The single seam that touches PHPStan's fragile internal plumbing
 * (`ContainerFactory`, `NodeScopeResolver`, the parser router, `FileHelper`) —
 * one implementation per supported PHPStan minor (design §2). The BC-stable
 * surfaces (`Scope`, `Type`, throw points, dynamic-return-type extensions) are
 * used directly by the translator / throw analyzer / tracer and are NOT confined
 * here.
 *
 * Everything an adapter does upward is expressed in `PhpParser\Node`, PHPStan
 * `Scope`, and `ReflectionProvider` — all BC-promised — so only booting, parser
 * priming and file walking need per-minor attention.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
interface RuntimeAdapter
{
    /**
     * Build the container, run Larastan's bootstrap files (which boot the host
     * Laravel app), and prime the project's source files. Idempotent.
     *
     * @throws BootFailedException when the container or Larastan bootstrap fails
     */
    public function boot(): void;

    /**
     * Add files to the analysed set on BOTH the `NodeScopeResolver` and the
     * `pathRoutingParser`, additively (the set only ever grows). This is the
     * Spike A body-stripping fix: a file not in the parser router's analysed set
     * is routed to `CleaningParser`, which deletes method bodies, so its returns
     * and throw points vanish silently. Every newly-analysed file — entry action
     * or descent target — must be primed BEFORE its first parse.
     *
     * @param  list<string>  $files  raw (un-normalised) paths
     */
    public function prime(array $files): void;

    /**
     * Parse a file and drive `NodeScopeResolver::processNodes` over it, invoking
     * `$callback(PhpParser\Node $node, PHPStan\Analyser\Scope $scope): void` for
     * every node. The file is primed first.
     */
    public function processFile(string $file, callable $callback): void;

    /** Normalise a path exactly the way PHPStan's parser router does. */
    public function normalize(string $file): string;

    /** The container's reflection provider (autoloader-backed, lazy). */
    public function reflectionProvider(): ReflectionProvider;
}
