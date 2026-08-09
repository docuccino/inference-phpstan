<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The engine's core determinism invariant: two independent runs of the same code
 * produce byte-identical serialized results (the upstream of the pipeline's
 * committed-artifact guarantee). Each run is a fresh subprocess with its own
 * tmpDir, so this also exercises cold-container reproducibility.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('produces an identical serialized ActionAnalysis across two cold runs', function (): void {
    $first = FixtureRunner::analyze('app/Http/Controllers/ThrowsController.php', 'App\\Http\\Controllers\\ThrowsController', 'abortAction');
    $second = FixtureRunner::analyze('app/Http/Controllers/ThrowsController.php', 'App\\Http\\Controllers\\ThrowsController', 'abortAction');

    expect(json_encode($first))->toBe(json_encode($second));
})->group('fixture');

it('produces an identical serialized ActionAnalysis for a union return across two cold runs', function (): void {
    $first = FixtureRunner::analyze('app/Http/Controllers/SpikeController.php', 'App\\Http\\Controllers\\SpikeController', 'unionAction');
    $second = FixtureRunner::analyze('app/Http/Controllers/SpikeController.php', 'App\\Http\\Controllers\\SpikeController', 'unionAction');

    expect(json_encode($first))->toBe(json_encode($second));
})->group('fixture');

it('harvests identical trace results across two cold runs', function (): void {
    $first = FixtureRunner::traceQb('app/Http/Controllers/UserListController.php', 'App\\Http\\Controllers\\UserListController', 'listUsers');
    $second = FixtureRunner::traceQb('app/Http/Controllers/UserListController.php', 'App\\Http\\Controllers\\UserListController', 'listUsers');

    expect(json_encode($first))->toBe(json_encode($second));
})->group('fixture');
