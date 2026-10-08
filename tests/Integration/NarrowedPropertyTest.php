<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine truth for a callable read with a property of `$this` narrowed: a resource collection's
 * `with()` with `$this->resource` the plain collection a list leaves there, or the paginator a page does.
 * Only the returns reachable for that class come back, however the `instanceof` test is spelled — two
 * contracts joined by `||`, two abstract classes negated and joined by `&&`, an inline ternary on one
 * contract a simple or cursor page does not implement, a `match (true)` arm listing both abstract classes.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('reads only the with() returns reachable for what $this->resource is', function (string $collection, string $resource, array $lines, array $keys): void {
    $file = 'app/Http/Resources/'.$collection.'.php';
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        $file,
        'App\\Http\\Resources\\'.$collection,
        'with',
        param: '$this->resource',
        narrowType: $resource,
        every: true,
    ));

    $keysAt = static fn ($site): ?array => $site->type instanceof ArrayShapeT
        ? array_map(static fn ($field): string => (string) $field->key, $site->type->fields)
        : null;

    expect(array_map(static fn ($site): int => $site->location->line, $analysis->returns))->toBe($lines)
        ->and(array_map($keysAt, $analysis->returns))->toBe($keys)
        ->and($analysis->dependencyFiles)->toContain(FixtureRunner::path($file));
})->with([
    'a plain list skips the paginator branch' => ['GazetteCollection', 'Illuminate\\Support\\Collection', [25], [['meta']]],
    'a length-aware page takes only it' => ['GazetteCollection', 'Illuminate\\Pagination\\LengthAwarePaginator', [22], [null]],
    'a negated test leaves a plain list both of its own returns' => ['DigestCollection', 'Illuminate\\Support\\Collection', [23, 26], [['meta'], []]],
    'a negated test leaves a page the last return' => ['DigestCollection', 'Illuminate\\Pagination\\LengthAwarePaginator', [29], [['meta']]],
    'a ternary on the contract answers a plain list with its else arm' => ['BriefCollection', 'Illuminate\\Support\\Collection', [22], [['meta', 'paged']]],
    'a ternary on the contract answers a length-aware page with its then arm' => ['BriefCollection', 'Illuminate\\Pagination\\LengthAwarePaginator', [21], [['paged']]],
    'a simple page is no length-aware one' => ['BriefCollection', 'Illuminate\\Pagination\\Paginator', [22], [['meta', 'paged']]],
    'a match arm on either abstract paginator answers a plain list with its default' => ['BulletinCollection', 'Illuminate\\Support\\Collection', [22], [['meta']]],
    // An arm's guard says when it may fire, not that it does, so the default after it is still read.
    'a match arm on either abstract paginator leaves a page itself and the default' => ['BulletinCollection', 'Illuminate\\Pagination\\CursorPaginator', [21, 22], [['paged'], ['meta']]],
    // Assigned before a return, the property holds something else there: the return is open to whatever was
    // wrapped, as one after a rebound parameter is, so a page keeps the meta the swap leads to.
    'a page swapped for its items reaches every return after the swap' => ['RelayCollection', 'Illuminate\\Pagination\\LengthAwarePaginator', [26, 29], [['meta'], []]],
    'a plain list is widened as well, since the swap may run before either return' => ['RelayCollection', 'Illuminate\\Support\\Collection', [26, 29], [['meta'], []]],
    'an unconditional with() is the same body whatever is wrapped' => ['ListedCollection', 'Illuminate\\Pagination\\LengthAwarePaginator', [18], [['meta', 'api_version']]],
])->group('fixture');

it('reaches the paginator branch for a cursor page through the other contract, and at most widens', function (): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Http/Resources/GazetteCollection.php',
        'App\\Http\\Resources\\GazetteCollection',
        'with',
        param: '$this->resource',
        narrowType: 'Illuminate\\Pagination\\CursorPaginator',
        every: true,
    ));
    $lines = array_map(static fn ($site): int => $site->location->line, $analysis->returns);

    // On PHPStan 2.2.0 the plain-list return also comes back for a cursor page — its scope after a false
    // `A || B` does not exclude the second contract — so meta reads optional there: wider, never narrower.
    // The newest 2.2.x and 2.3 answer the paginator branch alone.
    expect($lines)->toContain(22)
        ->and(array_values(array_diff($lines, [22, 25])))->toBe([]);
})->group('fixture');

it('reads every return where nothing about $this->resource is narrowed', function (): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Resources/GazetteCollection.php',
        'App\\Http\\Resources\\GazetteCollection',
        'with',
    ));

    // The premise: both branches count until the envelope is known, so `meta` is on one return of two.
    expect(array_map(static fn ($site): int => $site->location->line, $analysis->returns))->toBe([22, 25]);
})->group('fixture');
