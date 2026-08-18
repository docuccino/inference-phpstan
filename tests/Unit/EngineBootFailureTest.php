<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Analysis\PhpStanTypeEngineBuilder;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;

/*
 * A container that will not come up is the one failure the engine is not allowed to throw out of —
 * and, since the host adapter never imports this package, the returned engine is the only place it
 * can say what happened.
 */

it('degrades to a null engine carrying the boot error', function (): void {
    $config = new RuntimeConfig(
        projectRoot: '/tmp/docuccino-bootfail',
        tmpDir: '/tmp/docuccino-bootfail/tmp',
        phpVersion: PHP_VERSION_ID,
        projectPaths: ['/tmp/docuccino-bootfail/app'],
        // No Larastan here, so the container never gets as far as booting the app.
        larastanNeon: '/tmp/docuccino-bootfail/vendor/larastan/larastan/extension.neon',
    );

    $engine = (new PhpStanEngineFactory)->create($config, EngineConfig::forProject('/tmp/docuccino-bootfail/app'));

    expect($engine)->toBeInstanceOf(NullTypeEngine::class)
        ->and($engine->bootFailure())->toContain('extension.neon');
});

it('degrades the same way through the builder the adapter probes for by name', function (): void {
    // The seam itself: an adapter holds a `TypeEngineBuilder`, so a boot failure has to survive the
    // trip through it rather than being something only this package's factory knows.
    $engine = (new PhpStanTypeEngineBuilder)->build(
        projectRoot: '/tmp/docuccino-bootfail',
        tmpDir: '/tmp/docuccino-bootfail/tmp',
        vendorPath: '/tmp/docuccino-bootfail/vendor',
        primePaths: ['/tmp/docuccino-bootfail/app'],
        descendPaths: ['/tmp/docuccino-bootfail/app'],
    );

    expect($engine)->toBeInstanceOf(NullTypeEngine::class)
        ->and($engine->bootFailure())->toContain('extension.neon');
});
