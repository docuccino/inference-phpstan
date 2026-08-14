<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\ReturnValueFolder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

/**
 * In-process proof of the fold's DECLINE, which is the branch every un-analysable callee takes: a method the
 * analyser hands back nothing for folds to nothing, however often it is asked and whichever method of the
 * file it is asked about. Recovering a real body needs a booted analyser, so that half is the fixture
 * group's.
 */
it('declines a callee the analyser has no body for, without re-parsing its file', function (): void {
    $adapter = new class implements RuntimeAdapter
    {
        public int $processed = 0;

        public function boot(): void {}

        public function prime(array $files): void {}

        public function processFile(string $file, callable $callback): void
        {
            $this->processed++;
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
            throw new RuntimeException('not needed');
        }
    };

    $reflection = $this->createStub(ReflectionProvider::class);
    $reflection->method('hasClass')->willReturn(false);

    $folder = new ReturnValueFolder(new FileAnalyzer($adapter), $reflection);
    $class = 'Modules\\Billing\\PositionSearchQuery';
    $file = '/app/PositionSearchQuery.php';

    expect($folder->fold(new Callee($class, 'termFilter', $file), [], []))->toBeNull()
        ->and($folder->fold(new Callee($class, 'termFilter', $file), [ConstValue::scalar('q')], []))->toBeNull()
        ->and($folder->fold(new Callee($class, 'statusFilter', $file), [], []))->toBeNull()
        // The expensive half is cached by the file analysis, so three folds cost one parse.
        ->and($adapter->processed)->toBe(1);
});
