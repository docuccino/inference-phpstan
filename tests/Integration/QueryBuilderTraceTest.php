<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Ports Spike B's pass criteria — the Scramble-Pro-beater. Recovers
 * allowedFilters/Sorts literals (scalar + factory descriptors) two calls deep,
 * and detects pagination through a custom terminal with the per-page value from
 * the outermost call site — all with zero doc annotations.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array<string, mixed>
 */
function traceUserList(): array
{
    return FixtureRunner::traceQb(
        'app/Http/Controllers/UserListController.php',
        'App\\Http\\Controllers\\UserListController',
        'listUsers',
    );
}

it('recovers allowedFilters literals and factory descriptors two calls deep', function (): void {
    expect(traceUserList()['filters'])->toBe([
        "'name'",
        "AllowedFilter::exact('status')",
        "AllowedFilter::partial('email')",
    ]);
})->group('fixture');

it('recovers allowedSorts and defaultSort', function (): void {
    $harvest = traceUserList();

    expect($harvest['sorts'])->toBe(["'name'", "'created_at'"])
        ->and($harvest['default'])->toBe(["'name'"]);
})->group('fixture');

it('detects pagination through a custom terminal with the outermost per-page', function (): void {
    $harvest = traceUserList();

    expect($harvest['paginates'])->toBeTrue()
        ->and($harvest['perPage'])->toBe(25);

    $names = array_map(static fn (array $t): string => $t['terminal'], $harvest['terminals']);
    expect($names)->toContain('paginateList')
        ->and($names)->toContain('paginate');

    // The per-page (25) must come from the OUTERMOST call site — the custom
    // `paginateList(25)` terminal — NOT the inner vendor `paginate($perPage)` it
    // forwards to (whose argument is a non-constant variable, hence null).
    expect($harvest['outermost'])->toBe('paginateList');

    $perPageByTerminal = [];
    foreach ($harvest['terminals'] as $terminal) {
        $perPageByTerminal[$terminal['terminal']] = $terminal['perPage'];
    }
    expect($perPageByTerminal['paginateList'])->toBe(25)
        ->and($perPageByTerminal['paginate'])->toBeNull();
})->group('fixture');
