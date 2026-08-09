<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Composer\InstalledVersions;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapterFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Runtime\UnsupportedPhpStanVersionException;
use Docuccino\Inference\PhpStan\Runtime\V2_2\RuntimeAdapter as V2_2Adapter;

/*
 * The version gate in front of the runtime adapters: which PHPStan minors are accepted, and what a
 * rejected one tells the developer. The allowlist is deliberately closed (design §1) — it is widened
 * only once the CI matrix is green on a new minor — so the gate is the thing that has to be right.
 */

function runtimeConfig(): RuntimeConfig
{
    return new RuntimeConfig(
        projectRoot: '/tmp/docuccino-runtime-config',
        tmpDir: '/tmp/docuccino-runtime-config/tmp',
        phpVersion: PHP_VERSION_ID,
        projectPaths: ['/tmp/docuccino-runtime-config/app'],
    );
}

it('selects the 2.2/2.3 adapter for the PHPStan the suite is running against', function (): void {
    // Not a tautology: the whole point of the gate is that the INSTALLED version decides. The
    // composer constraint is `~2.2.0 || ~2.3.0`, so whichever of those CI resolved must be accepted
    // — a gate that rejected its own dependency would fail here rather than at analysis time.
    $version = InstalledVersions::getPrettyVersion('phpstan/phpstan') ?? '';

    expect($version)->toMatch('/^v?2\.[23]\./');

    expect((new RuntimeAdapterFactory)->create(runtimeConfig()))->toBeInstanceOf(V2_2Adapter::class);
});

it('names both the rejected version and the supported range when a minor is not allow-listed', function (): void {
    // The message is the only thing a developer on an unsupported minor sees, so it carries the
    // version they have, the range they need, and the rule for widening it.
    $exception = UnsupportedPhpStanVersionException::forVersion('2.9.1', '~2.2.0 || ~2.3.0');

    expect($exception->getMessage())
        ->toContain('2.9.1')
        ->toContain('~2.2.0 || ~2.3.0')
        ->toContain('CI is green');
});

it('reports an undetectable version as "unknown" rather than guessing', function (): void {
    // detectVersion() swallows Composer's OutOfRangeException for a package it cannot see; the
    // sentinel it substitutes has to reach the message, or the failure is unattributable.
    $exception = UnsupportedPhpStanVersionException::forVersion('unknown', '~2.2.0 || ~2.3.0');

    expect($exception->getMessage())->toContain('"unknown"');
});
