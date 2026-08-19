<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\ReturnValueFolder;
use PHPStan\Reflection\ReflectionProvider;

/**
 * In-process proof of the fold's DECLINE, which is the branch every un-analysable callee takes: a method the
 * analyser hands back nothing for folds to nothing, however often it is asked and whichever method of the
 * file it is asked about. Recovering a real body needs a booted analyser, so that half is the fixture
 * group's.
 */
it('declines a callee the analyser has no body for, without re-parsing its file', function (): void {
    $adapter = new ScriptedRuntimeAdapter;

    $reflection = $this->createStub(ReflectionProvider::class);
    $reflection->method('hasClass')->willReturn(false);

    $folder = new ReturnValueFolder(new FileAnalyzer($adapter, new FileWalks($adapter)), $reflection);
    $class = 'Modules\\Billing\\PositionSearchQuery';
    $file = '/app/PositionSearchQuery.php';

    expect($folder->fold(new Callee($class, 'termFilter', $file), [], []))->toBeNull()
        ->and($folder->fold(new Callee($class, 'termFilter', $file), [ConstValue::scalar('q')], []))->toBeNull()
        ->and($folder->fold(new Callee($class, 'statusFilter', $file), [], []))->toBeNull()
        // The expensive half is cached by the file analysis, so three folds cost one parse.
        ->and($adapter->totalPasses)->toBe(1);
});
