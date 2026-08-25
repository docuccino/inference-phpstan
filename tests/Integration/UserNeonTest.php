<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The headline of design §7 on the real engine: an application's own PHPStan config, included by the
 * one the engine generates, changes what inference recovers — with nothing Docuccino-specific in it.
 *
 * The baseline is pinned next door (FrameworkResponseReachabilityTest): `SsoRedirectController::reset`
 * hands back whatever the gateway answered, and the gateway's own return type is a bare `JsonResponse`,
 * so out of the box the payload is unrecoverable — a status stamped on it fluently is all there is to
 * recover. Here the app has told PHPStan what that call really answers, and the same action recovers the
 * payload as well.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('lets an application PHPStan extension shape what the engine recovers', function (): void {
    $analysis = FixtureRunner::analyzeWithConfig(
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'reset',
        dirname(__DIR__).'/Fixtures/user-neon/extension.neon',
    );

    /** @var list<array<string, mixed>> $returns */
    $returns = $analysis['returns'];

    expect($returns)->toHaveCount(1);
    $type = $returns[0]['type'];

    expect($type['kind'])->toBe('class')
        ->and($type['fqcn'])->toBe('Illuminate\\Http\\JsonResponse')
        // Bare without the include; the payload is here only because the app's own extension said so.
        ->and($type['typeArgs'])->not->toBe([])
        ->and($type['typeArgs'][0]['fqcn'] ?? null)->toBe('App\\Data\\ArticleData');
})->group('fixture');

it('analyses without a configured file that is not there, rather than failing', function (): void {
    // The degradation the adapter reports as `config.engine-neon-missing`: the engine skips the path
    // and answers what it can, so a mistyped key costs a warning and some precision, never a build.
    $analysis = FixtureRunner::analyzeWithConfig(
        'app/Http/Controllers/SsoRedirectController.php',
        'App\\Http\\Controllers\\SsoRedirectController',
        'reset',
        dirname(__DIR__).'/Fixtures/user-neon/absent.neon',
    );

    /** @var list<array<string, mixed>> $returns */
    $returns = $analysis['returns'];

    expect($returns)->toHaveCount(1)
        ->and($returns[0]['type']['fqcn'])->toBe('Illuminate\\Http\\JsonResponse')
        // Without the include the action recovers only what it states itself — the fluent status — and
        // the payload stays unresolved, which is the same answer as before the file was configured.
        ->and($returns[0]['type']['typeArgs'][0]['kind'] ?? null)->toBe('unknown')
        ->and($returns[0]['type']['typeArgs'][1]['value'] ?? null)->toBe(200);
})->group('fixture');
