<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\DocBlockTypeProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeCollection;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeError;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeOptional;

/**
 * Where reflection and docblocks meet in `classMetadata()`. A docblock REPLACES a reflected type only where
 * reflection says nothing usable — a bare `array`, `mixed`, no declared type — which is what lets a promoted
 * `array $errors` still document `list<ErrorDetailData>`; over a precise type it may only PARAMETERISE a
 * generic-less class. Real reflection over an autoloaded probe shaped like a spatie/laravel-data DTO, never
 * hand-built doubles.
 */
function probeMetadataType(string $property): DType
{
    foreach ((new ClassMetadataFactory)->forClass(new ClassRef(DocBlockTypeProbe::class))->properties as $meta) {
        if ($meta->name === $property) {
            return $meta->type;
        }
    }

    throw new RuntimeException('no such property: '.$property);
}

it('takes the precise type a docblock states where reflection is vague', function (string $property, DType $expected): void {
    // Every unqualified class name here is resolved through the declaring file's imports, so a wrong
    // resolution would show up as a ClassT under the wrong namespace rather than as a missing type.
    expect(probeMetadataType($property))->toEqual($expected);
})->with([
    // The promoted `array|ProbeOptional` case: reflection can express neither the element type nor the
    // list-ness, and both arms have to survive.
    'a promoted union whose array arm the @param types' => ['errors', UnionT::of([new ListT(new ClassT(ProbeError::class)), new ClassT(ProbeOptional::class)])],
    'a promoted keyed array' => ['counts', new MapT(ScalarT::string(), ScalarT::int())],
    // Promoted properties may be untyped, which reflection reports as no type at all.
    'a promoted property with no native type' => ['late', new ListT(ScalarT::int())],
    // The tag lives on the parent's constructor; this class's own constructor never mentions it.
    'a promoted property inherited from a parent' => ['inherited', new ListT(new ClassT(ProbeError::class))],
    // A plain property states its type in its own @var — the same gap for the same reason.
    'a plain property with a @var' => ['ownVar', new ListT(new ClassT(ProbeError::class))],
    // A promoted property's own @var is read too: it is where a Data class usually writes the generic,
    // beside the prose describing the member.
    'a promoted property with only a @var' => ['ownVarPromoted', new MapT(ScalarT::string(), ScalarT::int())],
    // Both tags speak, and the constructor @param is the more authoritative — its `list<ProbeError>` over
    // the property's `list<int>`.
    'a promoted property documented twice' => ['paramAndVar', new ListT(new ClassT(ProbeError::class))],
]);

it('parameterises a generic-less class type from the docblock that names its arguments', function (string $property, DType $expected): void {
    // Reflection has no syntax for generics, so a bare `ProbeCollection` is precise and still says nothing
    // about its elements. The docblock supplies only those arguments — never the class, never nullability.
    expect(probeMetadataType($property))->toEqual($expected);
})->with([
    'from the constructor @param' => ['collection', new ClassT(ProbeCollection::class, [ScalarT::int(), new ClassT(ProbeError::class)])],
    'from the property\'s own @var' => ['widenedCollection', new ClassT(ProbeCollection::class, [ScalarT::int(), new ClassT(ProbeError::class)])],
    // The declaration's nullability survives: the arguments are grafted onto the class arm and the null
    // arm is left alone.
    'through a nullable declaration' => ['nullableCollection', UnionT::of([
        new ClassT(ProbeCollection::class, [ScalarT::int(), new ClassT(ProbeError::class)]),
        new NullT,
    ])],
]);

it('leaves a precise class type alone when the docblock would do more than parameterise it', function (string $property): void {
    // The one-directional half of the rule. A docblock that names a different class — a subclass included,
    // which is the same mismatch — a different shape, or nothing parseable, adds no arguments, so the
    // reflected type stands. `widenedCollection` covers the fourth case above: its `@var` also writes
    // `|null` over a non-nullable declaration, and only its arguments are taken.
    expect(probeMetadataType($property))->toEqual(new ClassT(ProbeCollection::class));
})->with([
    'a different class' => ['otherCollection'],
    'a different shape entirely' => ['mismatchedCollection'],
    'a tag that does not parse' => ['garbledCollection'],
]);

it('keeps the reflected type when a docblock would not improve it', function (string $property, string $reason): void {
    $type = probeMetadataType($property);

    expect($type)->toBeInstanceOf(UnknownT::class)
        ->and($type->reason)->toBe($reason);
})->with([
    // A bare `array` in a tag says nothing reflection couldn't, so the native reason survives rather than
    // being laundered into a different flavour of unknown.
    'a promoted @param that is itself a bare array' => ['vague', 'no declared type'],
    'a @var that is itself a bare array' => ['vagueVar', 'no declared type'],
    'no tag at all' => ['noTag', 'untyped array'],
]);

it('never lets a docblock overrule a precise native type', function (): void {
    // The probe's constructor says `@param int $title` over a native `string`. Reflection is authoritative
    // when it is specific, so the tag is not even read.
    expect(probeMetadataType('title'))->toEqual(ScalarT::string());
});

it('enumerates the instance properties and nothing else', function (): void {
    $names = array_map(
        static fn (PropertyMetadata $p): string => $p->name,
        (new ClassMetadataFactory)->forClass(new ClassRef(DocBlockTypeProbe::class))->properties,
    );

    // Declared order, inherited included, the static left out — a static is never part of a payload.
    expect($names)->toBe([
        'ownVar', 'vagueVar', 'noTag', 'errors', 'counts', 'late', 'title', 'vague', 'paramAndVar',
        'ownVarPromoted', 'collection', 'nullableCollection', 'widenedCollection', 'otherCollection',
        'mismatchedCollection', 'garbledCollection', 'inherited', 'magic',
    ])
        ->and($names)->not->toContain('registry');
});

it('adds class-level @property tags without displacing a real property', function (): void {
    // The magic-attribute convention still fills in columns that declare no PHP property, and a native
    // property of the same name stays the more precise one.
    expect(probeMetadataType('magic'))->toEqual(ScalarT::int())
        ->and(probeMetadataType('ownVar'))->toEqual(new ListT(new ClassT(ProbeError::class)));
});

it('memoises per class and stays total for a class that cannot be resolved', function (): void {
    $factory = new ClassMetadataFactory;
    $missing = $factory->forClass(new ClassRef('Docuccino\\Nope\\NotAClass'));

    expect($missing->properties)->toBe([])
        ->and($missing->summary)->toBeNull()
        ->and($missing->dependencyFiles)->toBe([])
        ->and($factory->forClass(new ClassRef('Docuccino\\Nope\\NotAClass')))->toBe($missing)
        ->and($factory->forClass(new ClassRef(DocBlockTypeProbe::class)))
        ->toBe($factory->forClass(new ClassRef(DocBlockTypeProbe::class)));
});
