<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine proof of {@see FileWalks}: a walk replayed from a recording harvests exactly what the live pass
 * that recorded it harvested. Only the booted analyser can prove this — the recording hands out STABILISED
 * scopes, and whether those answer `getType()`/`constantValueOf()` the way a live fiber scope does is
 * PHPStan behaviour, not mechanics a stub can stand in for.
 *
 * The Query-Builder trace is the probe because it exercises the whole scope-dependent surface in one walk:
 * receiver typing to recognise a builder, argument folding for the filter literals and factory descriptors,
 * a deferred return fold, and interprocedural descent into a helper file.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('harvests a replayed trace exactly as a live one', function (): void {
    // Two subprocesses, two orders. The baseline traces first, so the Tracer's own live pass records the
    // controller; the replay run analyses the action first, so the METHOD harvest records it and both
    // traces read that recording. Cross-consumer sharing is the risk, and this is what it looks like.
    $live = FixtureRunner::traceQb(
        'app/Http/Controllers/UserListController.php',
        'App\\Http\\Controllers\\UserListController',
        'listUsers',
    );
    $replayed = FixtureRunner::traceQbReplay(
        'app/Http/Controllers/UserListController.php',
        'App\\Http\\Controllers\\UserListController',
        'listUsers',
    );

    // The analysis really did run first — otherwise the traces below would be recording, not replaying.
    expect($replayed['returns'])->toBeGreaterThan(0)
        ->and($replayed['first'])->toBe($live)
        // And a replay is idempotent: the second trace off the same recording harvests the same again.
        ->and($replayed['second'])->toBe($live);

    // The recorder is SHARED, not one per consumer. An analyzeAction plus two traces of the same file cost
    // exactly one walk of it — which is the whole point of the layer, and the half that byte-identical JSON
    // cannot see. Two FileWalks instances out of PhpStanEngineFactory would make this 2 or 3.
    expect($replayed['passes'])->toBe(1);

    // Guard against a mutually-empty pass: the harvest has to be the real one, not two blanks agreeing.
    expect($live['filters'])->toBe(["'name'", "AllowedFilter::exact('status')", "AllowedFilter::partial('email')"])
        ->and($live['paginates'])->toBeTrue()
        ->and($live['perPage'])->toBe(25);
})->group('fixture');
