<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\PoolRunner;

/**
 * Determinism invariants for the orchestrated engine, all out-of-process against the fixture app:
 * 1 worker vs 4 workers (with recycling) are byte-identical; cold cache vs warm cache are
 * byte-identical including diagnostics; and a poison action bisects to UnknownT plus an error
 * diagnostic while its siblings still succeed.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(PoolRunner::available());
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/docuccino-cache-test-*') ?: [] as $dir) {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

/**
 * The refined `JsonResponse` shape of a pool result: its folded status typeArg and each top-level body
 * member's DType kind. Keeps the refinement assertions below readable.
 *
 * @param  array<string, mixed>  $analysis  one serialized ActionAnalysis from the pool
 * @return array{status: array<string, mixed>, members: array<string, string>}
 */
function refinedShape(array $analysis): array
{
    /** @var array{kind: string, fqcn?: string, typeArgs?: list<array<string, mixed>>} $type */
    $type = $analysis['returns'][0]['type'];
    expect($type['kind'])->toBe('class')->and($type['fqcn'])->toBe('Illuminate\\Http\\JsonResponse');

    $members = [];
    /** @var list<array{key: string, type: array{kind: string}}> $fields */
    $fields = $type['typeArgs'][0]['fields'] ?? [];
    foreach ($fields as $field) {
        $members[$field['key']] = $field['type']['kind'];
    }

    return ['status' => $type['typeArgs'][1] ?? [], 'members' => $members];
}

it('produces byte-identical results for 1 worker vs 4 workers with recycling', function (): void {
    $one = PoolRunner::run(workers: 1, maxActionsPerWorker: 50);
    $four = PoolRunner::run(workers: 4, maxActionsPerWorker: 3);

    expect($four)->toBe($one)
        ->and(PoolRunner::decode($one))->toHaveCount(12);

    // Both refined shapes below prove the refinement / enum-fold / StatusMarkerT machinery actually ran
    // through the pool, so the byte-identity above is an assertion over real output rather than a no-op.
    $results = PoolRunner::decode($one);

    // A concrete enum case bound at the call site: accessors fold to per-case literals and the HTTP
    // status resolves to the folded 403, so its body `status` is a literal rather than a marker.
    $folded = refinedShape($results['App\\Http\\Controllers\\ProblemController::forbidden']);
    expect($folded['status'])->toBe(['kind' => 'literal', 'base' => 'int', 'value' => 403])
        ->and($folded['members']['type'])->toBe('literal')    // enum ->value → const URI
        ->and($folded['members']['title'])->toBe('literal')   // match()-method → const
        ->and($folded['members']['status'])->toBe('literal'); // folded from the same status() accessor

    // A status forwarded from the action's own parameter stays permissive rather than guessed, and the
    // body member reading that accessor survives as a StatusMarkerT for the response seam.
    $marked = refinedShape($results['App\\Http\\Controllers\\ProblemController::dynamic']);
    expect($marked['status']['kind'])->toBe('unknown')
        ->and($marked['members']['type'])->toBe('literal')    // the non-status literals still fold
        ->and($marked['members']['status'])->toBe('statusMarker');
})->group('fixture');

it('produces byte-identical results for cold cache vs warm cache', function (): void {
    $cacheDir = sys_get_temp_dir().'/docuccino-cache-test-'.bin2hex(random_bytes(6));

    $cold = PoolRunner::run(workers: 2, maxActionsPerWorker: 50, cacheDir: $cacheDir);
    $warm = PoolRunner::run(workers: 2, maxActionsPerWorker: 50, cacheDir: $cacheDir);

    expect($warm)->toBe($cold);
    // …and the warm run was served from the cache, not recomputed.
    expect(glob($cacheDir.'/actions/*.json') ?: [])->not->toBeEmpty();
})->group('fixture');

it('bisects a poison action to UnknownT + diagnostic while siblings succeed', function (): void {
    $poison = 'App\\Http\\Controllers\\SpikeController::listUsers';

    $results = PoolRunner::decode(
        PoolRunner::run(workers: 2, maxActionsPerWorker: 50, poisonSymbol: $poison),
    );

    expect($results)->toHaveKey($poison);

    /** @var array{returns: list<array{type: array{kind: string}}>, diagnostics: list<array{code: string, severity: string}>} $poisoned */
    $poisoned = $results[$poison];
    expect($poisoned['returns'][0]['type']['kind'])->toBe('unknown');
    $codes = array_map(static fn (array $d): string => $d['code'], $poisoned['diagnostics']);
    expect($codes)->toContain('inference.action-poisoned');

    // A sibling analysed by another worker (or on retry) still succeeds cleanly.
    $sibling = 'App\\Http\\Controllers\\ThrowsController::abortAction';
    expect($results)->toHaveKey($sibling);
    /** @var array{diagnostics: list<array{code: string}>} $siblingResult */
    $siblingResult = $results[$sibling];
    $siblingCodes = array_map(static fn (array $d): string => $d['code'], $siblingResult['diagnostics']);
    expect($siblingCodes)->not->toContain('inference.action-poisoned');
})->group('fixture');
