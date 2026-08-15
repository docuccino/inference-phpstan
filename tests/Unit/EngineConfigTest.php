<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Throwing\KnownThrowers;

/**
 * The engine's two config objects. Both are pure, parent-process values whose named constructors and
 * defaulted members decide how deep analysis descends and which files a booted container reflects — and
 * every caller of both is subprocess-only, so nothing else in the suite can prove them in process.
 */
it('builds an engine config for a project, with the descent bounds the engine ships', function (): void {
    // The two bounds differ on purpose (trace 4, throws 3); a silent change to either would change how
    // much of an app the engine reads with nothing failing.
    $config = EngineConfig::forProject('/app/src', '/app/modules');

    expect($config->projectPaths)->toBe(['/app/src', '/app/modules'])
        ->and($config->knownThrowers)->toBeInstanceOf(KnownThrowers::class)
        ->and($config->traceDepth)->toBe(4)
        ->and($config->throwDepth)->toBe(3)
        ->and($config->fileBudget)->toBe(40)
        // No vendor path means the return-type widening is off, not pointed at a guess.
        ->and($config->vendorPath)->toBeNull();
});

it('carries the vendor tree a trace visitor must never descend into', function (): void {
    $config = EngineConfig::forProjectWithVendor('/app/vendor', '/app/src');

    expect($config->vendorPath)->toBe('/app/vendor')
        ->and($config->projectPaths)->toBe(['/app/src'])
        ->and($config->traceDepth)->toBe(4);
});

it('resolves the larastan neon under the project root when none is given', function (): void {
    $config = new RuntimeConfig('/app', '/tmp/x', 80500, ['/app/app']);

    expect($config->resolvedLarastanNeon())->toBe('/app/vendor/larastan/larastan/extension.neon')
        // An explicit autoloader list is what a modular app passes; without one the root stands in.
        ->and($config->resolvedAutoloaderPaths())->toBe(['/app'])
        ->and($config->userNeon)->toBeNull();
});

it('prefers an explicit larastan neon and autoloader list over the derived ones', function (): void {
    $config = new RuntimeConfig(
        '/app',
        '/tmp/x',
        80500,
        ['/app/app'],
        larastanNeon: '/elsewhere/extension.neon',
        userNeon: '/app/docuccino.neon',
        autoloaderProjectPaths: ['/app/app', '/app/modules'],
    );

    expect($config->resolvedLarastanNeon())->toBe('/elsewhere/extension.neon')
        ->and($config->resolvedAutoloaderPaths())->toBe(['/app/app', '/app/modules'])
        ->and($config->userNeon)->toBe('/app/docuccino.neon');
});
