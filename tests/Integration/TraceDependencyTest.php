<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Fragment-cache soundness for the interprocedural trace, and the bound arithmetic that has to stay
 * unmoved while it is fixed. A trace's file set is what keys the fragment it feeds, so every file a
 * harvested fact was WRITTEN in belongs on it — including a trait's, which PHP hands to the walk of the
 * using class's file and so never names. What the trace recovers is {@see QueryBuilderTraceTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The export chain's allow-list, written in the trait the query class imports. */
const EXPORT_FILTERS = ["'sku'", "AllowedFilter::exact('status')"];

/** Its sortable columns, one hop past the trait. */
const EXPORT_SORTS = ["'sku'", "'created_at'"];

/**
 * @return array<string, mixed>
 */
function exportTrace(int $fileBudget = 40, int $traceDepth = 4): array
{
    return FixtureRunner::traceQbBounds(
        $fileBudget,
        $traceDepth,
        'app/Http/Controllers/ExportListController.php',
        'App\\Http\\Controllers\\ExportListController',
        'listExports',
    );
}

/**
 * @param  array<string, mixed>  $trace
 * @return list<string>
 */
function exportDependencyNames(array $trace): array
{
    /** @var list<string> $files */
    $files = $trace['dependencyFiles'];

    return array_map(static fn (string $file): string => basename($file), $files);
}

/**
 * The modular facet query, traced with the real visitor: its allow-list is SPREAD from a helper, and an
 * array return is not a type the trace follows — so the descent gate declines that hop and the return
 * fold is the only reader of the concern's body.
 *
 * @return array<string, mixed>
 */
function exportFacetTrace(): array
{
    return FixtureRunner::traceQbEnrich(
        'modules/Billing/ExportFacetQuery.php',
        'Modules\\Billing\\ExportFacetQuery',
        'query',
    );
}

it('depends on the file a traced body was written in', function (): void {
    // The allow-list is written in the concern the query class imports, and PHP reports the method as the
    // query class's own — so the walk harvests these entries out of a file it never opened by name. Only
    // the using class's file was ever recorded, and editing the trait then left every warm route
    // publishing filters the code no longer offers.
    $trace = exportTrace();

    expect($trace['filters'])->toBe(EXPORT_FILTERS) // the fact, so the row cannot pass on an empty harvest
        ->and(exportDependencyNames($trace))
        ->toContain('FiltersExports.php')
        ->toContain('ExportIndexQuery.php')
        // The trace's ROOT is the same fact one level up: the route resolved to the controller's file and
        // the action it runs is written in the concern that controller imports.
        ->toContain('ListsExports.php')
        ->toContain('ExportListController.php');
})->group('fixture');

it('counts none but the application\'s own files, so the frontier cannot move with the vendor tree', function (): void {
    // The tell of the class the frontier below belongs to, executed rather than stated. The budget is a
    // count of files OPENED, so whatever sits in the counted path decides at which budget each fact
    // appears — and a vendor file in there makes those coordinates a function of the installed major, not
    // of the fixture. Every hop of this chain is a file this repo writes: the controller, the query class,
    // the sorts helper and the custom terminal are opened, and the two traits are recorded off the walks
    // that read them. Nothing from `vendor/` is either.
    $trace = exportTrace();
    /** @var list<string> $files */
    $files = $trace['dependencyFiles'];

    expect(exportDependencyNames($trace))
        ->toEqualCanonicalizing([
            'ExportListController.php',
            'ListsExports.php',
            'ExportIndexQuery.php',
            'FiltersExports.php',
            'ExportSorts.php',
            'ListQueryBuilder.php',
        ])
        ->and(array_values(array_filter($files, static fn (string $file): bool => str_contains($file, '/vendor/'))))
        ->toBe([]);
})->group('fixture');

it('recovers the same facts at each descent bound, and depends on the trait only where it read one', function (
    int $fileBudget,
    int $traceDepth,
    array $filters,
    array $sorts,
    array $default,
    array $terminals,
    bool $dependsOnTrait,
): void {
    // The bound frontier, stated from what the two bounds MEAN: `fileBudget` counts the files the walk may
    // open and `traceDepth` the hops it may make from the root, so each row is the set of facts a walk with
    // exactly that much room can prove. Both coordinates are facts of the fixture. Every file in the
    // counted path is one this repo writes — the row above is that guard — and the order the budget is
    // spent in is the order the calls are WRITTEN, because a call is positioned by its own name
    // ({@see SourceOrder}): the action's chain reads `->query()` before `->paginateList(25)`, so the query
    // class opens before the custom terminal. Neither coordinate may read the installed Laravel, and the
    // first version of this table did: the two links of that chain share the receiver offset php-parser
    // reports for both, the sort tied, and the tie handed the order to PHPStan's node-callback order, which
    // the fixture matrix' two legs answer differently.
    //
    // So, by budget: slot 1 the controller, whose own body names the custom terminal; slot 2 the query
    // class the chain's first link resolves to, carrying its default sort and the filters the trait inlines
    // into that file; slot 3 the sorts helper one hop past the trait; slot 4 the custom terminal's own file
    // and the vendor `paginate()` behind it. By depth: the query class is one hop out, the trait body two,
    // the sorts helper three. The last row is the shipped default (40 / 4).
    //
    // Recording a file a body was WRITTEN in must cost the traversal nothing, and the tight budgets are
    // what hold that: were `depend()` charged a slot, the budget-2 row would never open the query class and
    // the budget-3 and budget-4 rows would stop reaching the sorts. The `dependsOnTrait` column is the
    // other half — a trait file is a dependency where a body was read out of it and not merely where a
    // callee resolved into it, which is why the depth-1 row wants it absent.
    $trace = exportTrace($fileBudget, $traceDepth);

    expect($trace['filters'])->toBe($filters)
        ->and($trace['sorts'])->toBe($sorts)
        ->and($trace['default'])->toBe($default)
        ->and($trace['terminals'])->toBe($terminals)
        ->and(in_array('FiltersExports.php', exportDependencyNames($trace), true))->toBe($dependsOnTrait);
})->with([
    'a budget for the action alone' => [1, 4, [], [], [], ['paginateList'], false],
    'one more, spent on the query class the chain opens with' => [2, 4, EXPORT_FILTERS, [], ["'sku'"], ['paginateList'], true],
    'one more, spent on the helper a hop past the trait' => [3, 4, EXPORT_FILTERS, EXPORT_SORTS, ["'sku'"], ['paginateList'], true],
    'one more, spent on the custom terminal the chain ends with' => [4, 4, EXPORT_FILTERS, EXPORT_SORTS, ["'sku'"], ['paginateList', 'paginate'], true],
    'a depth reaching the query class but not the trait body' => [40, 1, [], [], ["'sku'"], ['paginateList', 'paginate'], false],
    'a depth reaching the trait body but not past it' => [40, 2, EXPORT_FILTERS, [], ["'sku'"], ['paginateList', 'paginate'], true],
    'a depth reaching the helper past the trait' => [40, 3, EXPORT_FILTERS, EXPORT_SORTS, ["'sku'"], ['paginateList', 'paginate'], true],
    'the shipped bounds' => [40, 4, EXPORT_FILTERS, EXPORT_SORTS, ["'sku'"], ['paginateList', 'paginate'], true],
])->group('fixture');

it('depends on the file a folded return was written in', function (): void {
    // The other half of the same rule, on the one hop a fold may make that a descent may not: the helper's
    // array return is no followed type, so this concern's body is read by the fold alone. Both facets
    // recover and the file they are written in is on the list — and nothing else is, so the concern got
    // there by being read rather than by being walked.
    $trace = exportFacetTrace();

    /** @var list<array<string, mixed>> $filters */
    $filters = $trace['filters'];

    expect(array_column($filters, 'name'))->toBe(['facet', 'label'])
        ->and($trace['visitedBasenames'])->toBe(['NamesExportFacets.php', 'ExportFacetQuery.php']);
})->group('fixture');

it('invalidates a cached fragment when a file a traced fact was written in is edited', function (string $subject, string $edited): void {
    // The end of the chain, through the real cache: what the trace reports is what a fragment stores, and
    // editing either concern has to make the entry stale. Without its file on the list the entry stays
    // warm, which is a route publishing an allow-list its code no longer enforces.
    /** @var list<string> $dependencies */
    $dependencies = ($subject === 'descended' ? exportTrace() : exportFacetTrace())['dependencyFiles'];

    expect(fragmentAcrossDependencyEdit($dependencies, $edited))->toBe(['warm' => true, 'staleAfterEdit' => true]);
})->with([
    'the concern a descended body is written in' => ['descended', 'app/Queries/Concerns/FiltersExports.php'],
    'the concern a folded return is written in' => ['folded', 'modules/Billing/Concerns/NamesExportFacets.php'],
    'the concern the traced action itself is written in' => ['descended', 'app/Http/Controllers/Concerns/ListsExports.php'],
])->group('fixture');
