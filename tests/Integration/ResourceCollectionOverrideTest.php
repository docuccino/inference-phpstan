<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * `Resource::collection()` is `static::newCollection()`, resolved by late static binding, so the class a
 * list is sent as is whatever the called resource's `newCollection()` builds — an override anywhere in its
 * hierarchy decides it, and with it the `with()` members and `$wrap` of the response. `toResourceCollection()`
 * is `Resource::collection($this)`, so it reaches the same override. The item stays the collection's first
 * type argument whether or not the collection declares a template, because that is the slot every reader
 * of an anonymous collection reads its items from.
 *
 * A `@method` tag naming a real method is only a type: PHP calls the method, so the magic method the
 * analyser borrows the tag's `@throws` from is never reached. A tag naming no real method is a forward.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function listedCollectionAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/ListedCollectionController.php',
        'App\\Http\\Controllers\\ListedCollectionController',
        ['index', 'paginated', 'transformed', 'transformedPage', 'declared', 'featured', 'archived', 'ledger', 'drafts', 'journal', 'sketches', 'plain', 'account', 'export'],
    );
}

function listedCollectionType(string $method): string
{
    $returns = ActionAnalysis::fromArray(listedCollectionAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    $type = $returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class);
    assert($type instanceof ClassT);

    $args = array_map(static fn ($arg): string => $arg instanceof ClassT ? $arg->fqcn : $arg->canonicalKey(), $type->typeArgs);

    return $type->fqcn.($args === [] ? '' : '<'.implode(',', $args).'>');
}

/** @return list<string> the API errors an action publishes, as `Short@status` */
function listedCollectionSignals(string $method): array
{
    $out = [];
    foreach (ActionAnalysis::fromArray(listedCollectionAnalyses()[$method])->throws as $throw) {
        if ($throw->disposition->value === 'signal') {
            $out[] = substr((string) strrchr('\\'.$throw->exceptionFqcn, '\\'), 1).'@'.($throw->httpStatusHint ?? 'null');
        }
    }
    sort($out);

    return $out;
}

it('types a list as the collection the called resource\'s newCollection() builds', function (string $method, string $expected): void {
    expect(listedCollectionType($method))->toBe($expected);
})->with([
    // The override is on the abstract base; the item is the called member, not the base.
    'Resource::collection() through an inherited override' => ['index', 'App\\Http\\Resources\\ListedCollection<App\\Http\\Resources\\CatalogueResource>'],
    'Resource::collection() of a paginator' => ['paginated', 'App\\Http\\Resources\\ListedCollection<App\\Http\\Resources\\CatalogueResource>'],
    '->toResourceCollection(Resource::class) on a collection' => ['transformed', 'App\\Http\\Resources\\ListedCollection<App\\Http\\Resources\\CatalogueResource>'],
    '->toResourceCollection(Resource::class) on a paginator' => ['transformedPage', 'App\\Http\\Resources\\ListedCollection<App\\Http\\Resources\\CatalogueResource>'],
    // A tag narrowing collection() changes the type the analyser reads, not the method PHP runs.
    'Resource::collection() narrowed by a @method tag' => ['declared', 'App\\Http\\Resources\\ListedCollection<App\\Http\\Resources\\ShelfResource>'],
    // A collection declaring its own template keeps the resource the override states.
    'an override returning a generic collection of static' => ['archived', 'App\\Http\\Resources\\ArchiveCollection<App\\Http\\Resources\\ArchiveResource>'],
    // A named collection says what it collects itself; there is no item slot to fill.
    'an override returning a named collection' => ['ledger', 'App\\Http\\Resources\\LedgerCollection'],
    // No return type: the analyser reads the framework's docblock through the override, which names the
    // framework's collection; the body constructs the subclass on its only path, and that is what is sent.
    'an override declaring no return type' => ['drafts', 'App\\Http\\Resources\\ListedCollection<App\\Http\\Resources\\DraftResource>'],
    // The same untyped override building a NAMED collection, which is no subtype of the anonymous one the
    // inherited docblock states: the body is still what is sent.
    'an untyped override building a named collection' => ['journal', 'App\\Http\\Resources\\JournalCollection'],
    // Two classes by path: no one class is sent, so the stated collection stays — true, and vaguer.
    'an untyped override building one of two collections' => ['sketches', 'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection<App\\Http\\Resources\\SketchResource>'],
    'the framework\'s own newCollection()' => ['plain', 'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection<App\\Http\\Resources\\UserResource>'],
])->group('fixture');

it('publishes no error for a @method tag naming a real method, and keeps the forward for one naming none', function (string $method, array $expected): void {
    expect(listedCollectionSignals($method))->toBe($expected);
})->with([
    // JsonResource's __callStatic() declares BadMethodCallException; collection() is a real static method.
    'a static tag over an inherited static method' => ['declared', []],
    // The request's __call() declares it too; user() is a real method of the request.
    'an instance tag over an inherited method' => ['account', []],
    // Neither names a method the class has, so PHP forwards them and the forward can fail.
    'a static tag naming a macro' => ['featured', ['BadMethodCallException@500']],
    'an instance tag naming a macro' => ['export', ['BadMethodCallException@500']],
])->group('fixture');
