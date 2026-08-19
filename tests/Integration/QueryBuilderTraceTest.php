<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The Query-Builder trace against the real engine: recovers allowedFilters/Sorts
 * literals (scalar + factory descriptors) two calls deep, and detects pagination
 * through a custom terminal with the per-page value from the outermost call site —
 * all with zero doc annotations.
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

it('keeps a first-class-callable filter recovered beside the arguments that do fold', function (): void {
    // The pin for the one measured raw-vs-stabilised scope divergence (§2). Every consumer now reads a
    // stabilised scope, which types the first-class callable in
    // `AllowedFilter::callback('tag', $this->tagFilter(...))` as `mixed` where the raw fiber scope typed it
    // `Closure(...)`. Nothing downstream depends on that argument's TYPE, so the entry is recovered either
    // way — and the siblings in the same call fold from their arguments, so if the widening ever spread past
    // first-class callables they are what breaks here.
    $harvest = FixtureRunner::traceQbEnrich(
        'modules/Billing/ChargeController.php',
        'Modules\\Billing\\ChargeController',
        'index',
    );

    $byName = [];
    foreach ($harvest['filters'] as $filter) {
        $byName[$filter['name']] = $filter;
    }

    expect(array_keys($byName))->toBe(['status', 'active', 'tag', 'title_search', 'state']);

    // The callable itself is unreadable at the call site whichever scope answers, so the honest recovery is
    // the name and the kind, with no column guessed for it.
    expect($byName['tag']['kind'])->toBe('callback')
        ->and($byName['tag']['typeColumn'])->toBeNull()
        ->and($byName['tag']['columnKind'])->toBeNull();

    // The arguments that DO fold, in the same call: an enum class-string, a plain string key column, and a
    // typed `new` expression.
    expect($byName['status']['factoryEnum'])->toBe('App\\Enums\\ListingStatus')
        ->and($byName['active']['columnKind'])->toBe('scalar')
        ->and($byName['title_search']['filterClass'])->toBe('App\\Filters\\ListingTitleSearchFilter');
})->group('fixture');
