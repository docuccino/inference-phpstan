<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

/**
 * In-process mechanics coverage for {@see FileAnalyzer}: the harvest/collection is driven by a
 * controllable adapter (the node WATCHING itself — pairing returns/closures/assignments with scope — is
 * real-engine behaviour, proven by the --group=fixture suites). Here a no-emit adapter exercises the
 * memoisation and empty-collection paths deterministically.
 */
function fileAnalyzerWithRecordingAdapter(int &$calls): FileAnalyzer
{
    $adapter = new class($calls) implements RuntimeAdapter
    {
        /** @param  int  $calls  incremented on each processFile pass, so cache hits are observable */
        public function __construct(private int &$calls) {}

        public function boot(): void {}

        public function prime(array $files): void {}

        public function processFile(string $file, callable $callback): void
        {
            $this->calls++;
        }

        public function normalize(string $file): string
        {
            return $file;
        }

        public function stableScope(Scope $scope): Scope
        {
            return $scope;
        }

        public function reflectionProvider(): ReflectionProvider
        {
            throw new RuntimeException('not used in this unit');
        }
    };

    return new FileAnalyzer($adapter);
}

it('harvests methods, closures and array assignments, memoising each per normalised file', function (): void {
    $calls = 0;
    $analyzer = fileAnalyzerWithRecordingAdapter($calls);

    // First access of each harvest kind runs one pass; the no-emit adapter collects nothing.
    expect($analyzer->analyze('/x.php'))->toBe([])
        ->and($analyzer->closures('/x.php'))->toBe([])
        ->and($analyzer->arrayAssignments('/x.php'))->toBe([])
        ->and($calls)->toBe(3);

    // Re-access hits the per-file cache for each kind — no further passes.
    expect($analyzer->analyze('/x.php'))->toBe([])
        ->and($analyzer->closures('/x.php'))->toBe([])
        ->and($analyzer->arrayAssignments('/x.php'))->toBe([])
        ->and($calls)->toBe(3);
});
