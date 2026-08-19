<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Closure;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use RuntimeException;
use WeakMap;

/**
 * A container-free {@see RuntimeAdapter} that emits a scripted node/scope sequence per file and counts its
 * passes, so everything built on a walk — memoisation, recording, replay, the budget — is testable without
 * booting PHPStan. Whether a real walk answers types the way this pretends to is the fixture group's job.
 *
 * `stableScope()` hands back a DISTINCT object per raw scope, stable across calls, so a test can tell a
 * stabilised scope from the one a pass handed out. The map is WEAK, so this adapter never keeps a scope the
 * pass itself dropped alive — which is what makes scope lifetime testable at all.
 *
 * A file's pass is either a fixed list of pairs or a closure handed the walk callback; the closure form
 * exists for a test that has to create and drop its scopes DURING the pass.
 *
 * @phpstan-type ScriptedPass list<array{Node, Scope}>|Closure(callable(Node, Scope): void): void
 */
final class ScriptedRuntimeAdapter implements RuntimeAdapter
{
    /**
     * Live passes per file, so a replay is observable.
     *
     * @var array<string, int>
     */
    public array $passes = [];

    public int $totalPasses = 0;

    /** @var WeakMap<Scope, Scope> */
    private WeakMap $stabilised;

    /**
     * @param  array<string, ScriptedPass>  $script  file → what its pass emits; an unscripted file emits nothing
     * @param  int  $analysedFiles  what `analysedFileCount()` reports; public so a test can grow the set
     */
    public function __construct(
        private readonly array $script = [],
        public int $analysedFiles = 0,
    ) {
        $this->stabilised = new WeakMap;
    }

    public function boot(): void {}

    public function prime(array $files): void {}

    public function processFile(string $file, callable $callback): void
    {
        $this->passes[$file] = ($this->passes[$file] ?? 0) + 1;
        $this->totalPasses++;

        $pass = $this->script[$file] ?? [];
        if ($pass instanceof Closure) {
            $pass(static function (Node $node, Scope $scope) use ($callback): void {
                $callback($node, $scope);
            });

            return;
        }

        foreach ($pass as [$node, $scope]) {
            $callback($node, $scope);
        }
    }

    public function analysedFileCount(): int
    {
        return $this->analysedFiles;
    }

    public function normalize(string $file): string
    {
        return $file;
    }

    public function stableScope(Scope $scope): Scope
    {
        // A stand-in for toMutatingScope(), which is likewise a function of the scope it is asked about.
        return $this->stabilised[$scope] ??= clone $scope;
    }

    public function reflectionProvider(): ReflectionProvider
    {
        throw new RuntimeException('the scripted adapter resolves no reflection');
    }
}
