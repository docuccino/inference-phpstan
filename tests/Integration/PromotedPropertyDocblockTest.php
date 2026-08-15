<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Where a Data class writes its generics, against the real engine. `classMetadata()` reads the constructor's
 * `@param` block AND the promoted parameter's own `@var` — the form a real app reaches for, because that is
 * where the prose describing the member already sits — and parameterises a generic-blind class type from
 * either. These tests pin every form the fixture app writes, so none can silently regress.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** Property name → resolved type, for a fixture-app class. */
function metadataTypes(string $fqcn): array
{
    $types = [];
    foreach (ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn))->properties as $property) {
        $types[$property->name] = $property->type;
    }

    return $types;
}

it('types a promoted array property from the constructor @param it was documented with', function (): void {
    // `@param array<string, mixed> $context` in the constructor block, no `@var` of its own → a real MapT,
    // which emits {"type": "object", "additionalProperties": {}}.
    $context = metadataTypes('App\\Data\\SnapshotData')['context'];

    expect($context)->toBeInstanceOf(MapT::class)
        ->and($context->key)->toEqual(ScalarT::string())
        ->and($context->value)->toBeInstanceOf(UnknownT::class);
})->group('fixture');

it('types a promoted array property from the @var it wrote beside the prose', function (string $property, DType $expected): void {
    // Every generic here is written in the promoted parameter's OWN docblock — the one the summary and
    // `@example` are already read off — so the type and the prose now come out of the same tag block.
    expect(metadataTypes('App\\Data\\SnapshotData')[$property])->toEqual($expected);
})->with([
    // @var array<string, mixed>
    'a map' => ['candidate', new MapT(ScalarT::string(), new UnknownT('mixed'))],
    // @var array<string, array<string, string|null>>
    'a nested map' => ['theme_data', new MapT(
        ScalarT::string(),
        new MapT(ScalarT::string(), UnionT::of([ScalarT::string(), new NullT])),
    )],
    // @var list<SnapshotFormData> — resolved through the declaring file's imports
    'a list of Data objects' => ['forms', new ListT(new ClassT('App\\Data\\SnapshotFormData'))],
    // @var array<int, string> — an int-capable key is a JSON array, not an object
    'an int-keyed array' => ['permissions', new ListT(ScalarT::string())],
    // @phpstan-var list<SnapshotFormData> — the analyser-prefixed tag is read like the plain one
    'an analyser-prefixed tag' => ['attachments', new ListT(new ClassT('App\\Data\\SnapshotFormData'))],
])->group('fixture');

it('reads a positional tuple out of a constructor @param as a LIST shape', function (): void {
    // `@param array{float, float} $position`. The grammar has only the KEYS to go on — no PHPStan list
    // accessory reaches it — so the `0..n` sequence is what has to make this a JSON array. A shape read
    // as an object would be documented with `"0"`/`"1"` property names, which no JSON payload has.
    $declared = metadataTypes('App\\Data\\UpdateNodeData')['position'];

    // `|Optional` is a spatie presence marker unioned into the declared type; the shape is the member.
    expect($declared)->toBeInstanceOf(UnionT::class);
    $position = array_values(array_filter($declared->members, static fn (DType $m): bool => $m instanceof ArrayShapeT))[0] ?? null;

    expect($position)->toBeInstanceOf(ArrayShapeT::class)
        ->and($position->isList)->toBeTrue()
        ->and(array_map(static fn ($f) => $f->key, $position->fields))->toBe([0, 1])
        ->and(array_map(static fn ($f) => $f->type, $position->fields))->toEqual([ScalarT::float(), ScalarT::float()]);
})->group('fixture');

it('keeps the prose of the property it takes the type from', function (): void {
    // The summary, the example and the type all come off the one docComment.
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Data\\SnapshotData'));

    $permissions = null;
    foreach ($metadata->properties as $property) {
        if ($property->name === 'permissions') {
            $permissions = $property;
        }
    }

    expect($permissions?->summary)->toBe('Flat list of permission strings the candidate held at submit.')
        ->and($permissions?->example)->toBe('["listing.view", "listing.create"]')
        ->and($permissions?->type)->toEqual(new ListT(ScalarT::string()));
})->group('fixture');

it('parameterises a natively-typed DataCollection from its constructor @param', function (): void {
    // A bare `DataCollection` reflects to a precise ClassT that still says nothing about its elements, so
    // the docblock is read for the arguments alone — the class it names is the same one.
    $factors = metadataTypes('App\\Data\\MfaChallengeData')['mfa_factors'];

    expect($factors)->toEqual(new ClassT(
        'Spatie\\LaravelData\\DataCollection',
        [ScalarT::int(), new ClassT('App\\Data\\SnapshotFormData')],
    ));
})->group('fixture');

it('types a natively declared backed-enum property as an EnumT', function (): void {
    // Reflection answers with the enum and its cases…
    $status = metadataTypes('App\\Data\\SnapshotFormData')['status'];

    expect($status)->toBeInstanceOf(EnumT::class)
        ->and($status->fqcn)->toBe('App\\Enums\\ListingStatus')
        ->and($status->cases)->toBe(['Open', 'Closed', 'Draft']);
})->group('fixture');

it('types the SAME enum the same way when only a @property tag declares it', function (): void {
    // …and so does the type-string grammar, for App\Models\Listing's magic `status` column documented the
    // ide-helper way (`@property ListingStatus $status`). Two halves had to meet for this: the grammar
    // asking whether a name is an enum, and the tag being parsed with the declaring file's imports — a
    // short name no enum could ever be reflected from otherwise.
    $status = metadataTypes('App\\Models\\Listing')['status'];

    expect($status)->toEqual(new EnumT('App\\Enums\\ListingStatus', ['Open', 'Closed', 'Draft']));
})->group('fixture');

it('recovers a request DTO\'s map and list generics, so nothing downstream can blame inference', function (): void {
    // The request-side control for the validation-rule collapse pinned in the adapter's
    // SpatieDataRealShapeTest: both generics really are here, in full, before the rule vocabulary
    // gets them.
    $types = metadataTypes('App\\Data\\SaveAnswersData');

    expect($types['answers'])->toBeInstanceOf(UnionT::class)
        ->and(array_filter($types['answers']->members, static fn (DType $m): bool => $m instanceof MapT))->not->toBeEmpty()
        ->and(array_filter($types['answers']->members, static fn (DType $m): bool => $m instanceof NullT))->not->toBeEmpty()
        ->and($types['touched_fields'])->toEqual(new ListT(ScalarT::string()));
})->group('fixture');
