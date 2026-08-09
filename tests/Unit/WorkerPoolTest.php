<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Inference\PhpStan\Cache\FilesystemEngineResultCache;
use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;
use Docuccino\Inference\PhpStan\Orchestration\OrchestrationConfig;
use Docuccino\Inference\PhpStan\Orchestration\WorkerPool;

/**
 * Exercises the parent orchestrator's scheduling, recycling, containment and
 * cache front-door against a PHPStan-free stub worker — fast enough for the
 * default suite, so the tricky logic is covered without the fixture app.
 */
function poolConfig(int $workers, int $maxActions = 50, string $poison = ''): OrchestrationConfig
{
    return new OrchestrationConfig(
        workerBootstrap: dirname(__DIR__).'/bin/stub-worker-bootstrap.php',
        workers: $workers,
        maxActionsPerWorker: $maxActions,
        perActionTimeoutSeconds: 30.0,
        batchSize: 4,
        env: $poison !== '' ? ['DOCUCCINO_POISON_SYMBOL' => $poison] : [],
    );
}

/**
 * @return list<ActionRef>
 */
function stubRefs(int $count): array
{
    $refs = [];
    for ($i = 0; $i < $count; $i++) {
        $refs[] = new ActionRef("/app/Action{$i}.php", "App\\Action{$i}", 'handle', $i + 1);
    }

    return $refs;
}

/**
 * @param  array<string, ActionAnalysis>  $results
 * @return array<string, array<string, mixed>>
 */
function serializeResults(array $results): array
{
    return array_map(static fn (ActionAnalysis $a): array => $a->toArray(), $results);
}

it('analyses every action and returns results keyed and sorted by id', function (): void {
    $pool = new WorkerPool(poolConfig(workers: 3));

    $results = $pool->analyze(stubRefs(7));

    expect($results)->toHaveCount(7);

    $keys = array_keys($results);
    $sorted = $keys;
    sort($sorted);
    expect($keys)->toBe($sorted);

    foreach ($results as $analysis) {
        expect($analysis->returns[0]->type->toArray()['kind'])->toBe('unknown');
    }
});

it('completes the full set even when workers recycle mid-run', function (): void {
    $pool = new WorkerPool(poolConfig(workers: 2, maxActions: 2));

    $results = $pool->analyze(stubRefs(9));

    expect($results)->toHaveCount(9);
});

it('bisects a poison action to a poison result while siblings succeed', function (): void {
    $poison = 'App\\Action3::handle';
    $pool = new WorkerPool(poolConfig(workers: 2, poison: $poison));

    $results = $pool->analyze(stubRefs(6));

    expect($results)->toHaveCount(6)->toHaveKey($poison);

    $codes = array_map(
        static fn ($d): string => $d->code,
        $results[$poison]->diagnostics,
    );
    expect($codes)->toContain('inference.action-poisoned')
        ->and($results[$poison]->returns[0]->type->toArray()['reason'] ?? '')->toContain('aborted');

    // Every non-poison action produced the stub's clean analysis.
    foreach ($results as $id => $analysis) {
        if ($id === $poison) {
            continue;
        }
        $siblingCodes = array_map(static fn ($d): string => $d->code, $analysis->diagnostics);
        expect($siblingCodes)->toContain('stub.analysed')->not->toContain('inference.action-poisoned');
    }
});

it('serves warm results from the cache front-door, bypassing the workers', function (): void {
    $dir = sys_get_temp_dir().'/docuccino-pool-cache-'.bin2hex(random_bytes(6));
    $cache = new FilesystemEngineResultCache($dir);
    $fingerprint = new VersionFingerprint('e1', 'p1', 'l1', 'n1', 'c1');
    $refs = stubRefs(4);

    // Cold run populates the cache.
    $cold = (new WorkerPool(poolConfig(workers: 2), $cache, $fingerprint))->analyze($refs);
    expect(glob($dir.'/actions/*.json') ?: [])->toHaveCount(4);

    // Warm run poisons an already-cached id: the front-door hit must bypass the
    // worker entirely, so the good cached result is returned, not a poison one.
    $poison = 'App\\Action1::handle';
    $warm = (new WorkerPool(poolConfig(workers: 2, poison: $poison), $cache, $fingerprint))->analyze($refs);

    expect(json_encode(serializeResults($warm)))->toBe(json_encode(serializeResults($cold)));
    $warmCodes = array_map(static fn ($d): string => $d->code, $warm[$poison]->diagnostics);
    expect($warmCodes)->toContain('stub.analysed')->not->toContain('inference.action-poisoned');

    exec('rm -rf '.escapeshellarg($dir));
});
