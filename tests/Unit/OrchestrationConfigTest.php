<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Inference\PhpStan\Orchestration\OrchestrationConfig;

it('honours an explicit worker count', function (): void {
    $config = new OrchestrationConfig(workerBootstrap: '/b.php', workers: 3);

    expect($config->resolvedWorkers())->toBe(3);
});

it('defaults workers to min(cores-1, 8) floored at 1', function (): void {
    $config = new OrchestrationConfig(workerBootstrap: '/b.php');

    $resolved = $config->resolvedWorkers();
    expect($resolved)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(8);
});

it('defaults the worker script to the package binary and php to the current binary', function (): void {
    $config = new OrchestrationConfig(workerBootstrap: '/b.php');

    expect($config->resolvedWorkerScript())->toEndWith('/bin/worker.php')
        ->and(is_file($config->resolvedWorkerScript()))->toBeTrue()
        ->and($config->resolvedPhpBinary())->toBe(PHP_BINARY);
});

it('carries the documented defaults', function (): void {
    $config = new OrchestrationConfig(workerBootstrap: '/b.php');

    expect($config->maxActionsPerWorker)->toBe(50)
        ->and($config->rssLimitBytes)->toBe(1_073_741_824)
        ->and($config->perActionTimeoutSeconds)->toBe(60.0)
        ->and($config->batchSize)->toBe(8);
});
