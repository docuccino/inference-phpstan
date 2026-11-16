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

it('gates on the same allowlist the composer constraint names', function (): void {
    // The allowlist lived in three places that had to move together: the composer constraint, the
    // hand-written version check, and the range the failure message quotes. The last two are one
    // constant now; this pins that constant to the first, so widening one alone fails here rather
    // than as an install that resolves a minor the gate then rejects.
    /** @var array{require?: array<string, string>} $manifest */
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($manifest['require']['phpstan/phpstan'] ?? null)->toBe(RuntimeAdapterFactory::supportedRange());
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
    // detectVersion() answers "unknown" for a package no installed set gives a version; the sentinel
    // has to reach the message, or the failure is unattributable.
    $exception = UnsupportedPhpStanVersionException::forVersion('unknown', '~2.2.0 || ~2.3.0');

    expect($exception->getMessage())->toContain('"unknown"');
});

it('reads the version the application installed, past a set that only replaces the package', function (array $installed, ?string $expected): void {
    // Once PHPStan's phar has loaded, its own Composer metadata is the FIRST set the process holds, and there
    // `phpstan/phpstan` is replaced by the source package with no version of its own. A read that stopped at
    // the first set naming the package found none, and every engine booted after the first refused to start.
    expect(RuntimeAdapterFactory::installedVersion($installed, 'phpstan/phpstan'))->toBe($expected);
})->with(function (): array {
    $phar = ['root' => ['name' => 'phpstan/phpstan-src'], 'versions' => [
        'phpstan/phpstan' => ['dev_requirement' => false, 'replaced' => ['2.3.x']],
        'phpstan/phpstan-src' => ['pretty_version' => '2.3.5'],
    ]];
    $application = ['root' => ['name' => 'laravel/laravel'], 'versions' => [
        'phpstan/phpstan' => ['pretty_version' => '2.3.5', 'dev_requirement' => true],
    ]];

    return [
        'the application alone' => [[$application], '2.3.5'],
        'behind the phar' => [[$phar, $application], '2.3.5'],
        'only replaced' => [[$phar], null],
        'not installed' => [[['versions' => []]], null],
        'nothing installed' => [[], null],
        'a set not shaped as one' => [['nonsense', ['versions' => 'nonsense']], null],
    ];
});
