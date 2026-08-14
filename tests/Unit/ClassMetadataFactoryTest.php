<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\DocBlockTypeProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeError;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeOptional;

/**
 * Where reflection and docblocks meet in `classMetadata()`. A native type wins outright; a docblock is read
 * only where reflection says nothing usable — a bare `array`, `mixed`, no declared type — which is what lets
 * a promoted `array $errors` still document `list<ErrorDetailData>`. Real reflection over an autoloaded probe
 * shaped like a spatie/laravel-data DTO, never hand-built doubles.
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
    expect($names)->toBe(['ownVar', 'vagueVar', 'noTag', 'errors', 'counts', 'late', 'title', 'vague', 'inherited', 'magic'])
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
