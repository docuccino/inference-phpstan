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
 * Real-engine coverage for `classMetadata()` over an idiomatic spatie Data class: promoted constructor
 * properties whose element types exist only in the constructor's `@param` tags, in a real installed app where
 * `Optional` is an imported short name. Reflection alone would leave `errors` as an untyped array, and a
 * property with no schema type at all reaches the document as a lone description.
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
