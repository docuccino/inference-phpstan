<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine coverage for what `classMetadata()` reports, over two idiomatic shapes in a real installed
 * app.
 *
 * A spatie Data class: promoted constructor properties whose element types exist only in the
 * constructor's `@param` tags, where `Optional` is an imported short name. Reflection alone would leave
 * `errors` as an untyped array, and a property with no schema type at all reaches the document as a lone
 * description.
 *
 * An Eloquent model: the factory reads NATIVE public properties beside the `@property` tags, and every
 * model inherits six of them from the framework. That is the report's shape, not a defect — a consumer
 * of this metadata that wants a model's serialised attributes has to know the six by name and drop
 * them, which is what the Laravel adapter's model schema does.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** Property name → resolved type, for the fixture app's problem-document Data class. */
function problemDataTypes(): array
{
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Data\\ProblemDocumentData'));

    $types = [];
    foreach ($metadata->properties as $property) {
        $types[$property->name] = $property->type;
    }

    return $types;
}

it('types a promoted array property from the constructor @param it was documented with', function (): void {
    $types = problemDataTypes();

    expect($types['errors'])->toEqual(UnionT::of([
        new ListT(ScalarT::string()),
        new ClassT('Spatie\\LaravelData\\Optional'),
    ]))
        // The natively-typed members are untouched: reflection is authoritative wherever it is specific.
        ->and($types['status'])->toEqual(ScalarT::int())
        ->and($types['instance'])->toEqual(UnionT::of([ScalarT::string(), new ClassT('Spatie\\LaravelData\\Optional')]));
})->group('fixture');

it('keeps every property of the class, whatever the docblock says', function (): void {
    expect(array_keys(problemDataTypes()))->toBe(['type', 'title', 'status', 'detail', 'instance', 'errors'])
        ->and(problemDataTypes())->each->toBeInstanceOf(DType::class);
})->group('fixture');

/**
 * The six public properties an Eloquent model inherits from `Illuminate\Database\Eloquent\Model`, which
 * the factory reports beside the model's documented columns because they really are native public
 * properties of the class. None is ever in a response — `attributesToArray()` reads the attribute array,
 * the appends and the relations, and a declared property is in none of the three — so a reader that
 * published them would promise every model six booleans that never arrive. Stated here because the drop
 * downstream is only necessary while this stays true.
 */
it('reports the framework bookkeeping properties a model inherits, beside its documented columns', function (): void {
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Models\\Product'));
    $names = array_map(static fn ($property): string => $property->name, $metadata->properties);

    expect($names)->toContain('incrementing', 'preventsLazyLoading', 'exists', 'wasRecentlyCreated', 'timestamps', 'usesUniqueIds')
        // Beside, not instead of: the `@property` column universe is reported too.
        ->and($names)->toContain('id', 'sku', 'description', 'name');
})->group('fixture');
