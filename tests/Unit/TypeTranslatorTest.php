<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\CallableT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Colour;
use Docuccino\Inference\PhpStan\Translation\TranslationBudget;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\Accessory\AccessoryLiteralStringType;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateTypeFactory;
use PHPStan\Type\Generic\TemplateTypeScope;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\VoidType;

/**
 * The translator touches only PHPStan's BC-promised Type hierarchy and needs no
 * booted container — so these are true unit tests over directly-constructed
 * types.
 */
function translate(Type $type): DType
{
    return (new TypeTranslator)->translate($type);
}

it('maps general scalars', function (): void {
    expect(translate(new IntegerType))->toEqual(ScalarT::int())
        ->and(translate(new StringType))->toEqual(ScalarT::string())
        ->and(translate(new FloatType))->toEqual(ScalarT::float())
        ->and(translate(new BooleanType))->toEqual(ScalarT::bool());
});

it('maps constant scalars to literals', function (): void {
    expect(translate(new ConstantStringType('x')))->toEqual(new LiteralT('x'))
        ->and(translate(new ConstantIntegerType(5)))->toEqual(new LiteralT(5))
        ->and(translate(new ConstantBooleanType(true)))->toEqual(new LiteralT(true));
});

it('maps null and mixed', function (): void {
    expect(translate(new NullType))->toBeInstanceOf(NullT::class)
        ->and(translate(new MixedType))->toBeInstanceOf(UnknownT::class);
});

it('flattens and canonically sorts unions', function (): void {
    $a = translate(new UnionType([new IntegerType, new StringType]));
    $b = translate(new UnionType([new StringType, new IntegerType]));

    expect($a->toArray())->toBe($b->toArray())
        ->and($a)->toBeInstanceOf(UnionT::class);
});

it('renders nullability as a union ending in null', function (): void {
    $union = translate(new UnionType([new StringType, new NullType]));

    expect($union)->toBeInstanceOf(UnionT::class);
    /** @var UnionT $union */
    $members = $union->members;
    expect($members[count($members) - 1])->toBeInstanceOf(NullT::class);
});

it('maps plain objects to ClassT', function (): void {
    $type = translate(new ObjectType('App\\Models\\User'));

    expect($type)->toBeInstanceOf(ClassT::class);
    /** @var ClassT $type */
    expect($type->fqcn)->toBe('App\\Models\\User')
        ->and($type->typeArgs)->toBe([]);
});

it('threads generic type arguments through ClassT', function (): void {
    $type = translate(new GenericObjectType('Illuminate\\Support\\Collection', [
        new IntegerType,
        new ObjectType('App\\Models\\User'),
    ]));

    expect($type)->toBeInstanceOf(ClassT::class);
    /** @var ClassT $type */
    expect($type->fqcn)->toBe('Illuminate\\Support\\Collection')
        ->and($type->typeArgs)->toHaveCount(2)
        ->and($type->typeArgs[1])->toBeInstanceOf(ClassT::class);
});

it('maps an enum object to EnumT with its cases', function (): void {
    $type = translate(new ObjectType(Colour::class));

    expect($type)->toBeInstanceOf(EnumT::class);
    /** @var EnumT $type */
    expect($type->fqcn)->toBe(Colour::class)
        ->and($type->cases)->toBe(['Red', 'Green', 'Blue']);
});

it('maps a constant array to an array shape with optional keys', function (): void {
    $builder = ConstantArrayTypeBuilder::createEmpty();
    $builder->setOffsetValueType(new ConstantStringType('id'), new IntegerType);
    $builder->setOffsetValueType(new ConstantStringType('name'), new StringType, optional: true);

    $type = translate($builder->getArray());

    expect($type)->toBeInstanceOf(ArrayShapeT::class);
    /** @var ArrayShapeT $type */
    expect($type->fields)->toHaveCount(2)
        ->and($type->fields[0]->key)->toBe('id')
        ->and($type->fields[0]->optional)->toBeFalse()
        ->and($type->fields[1]->key)->toBe('name')
        ->and($type->fields[1]->optional)->toBeTrue();
});

it('maps a general keyed array to MapT', function (): void {
    $type = translate(new ArrayType(new StringType, new IntegerType));

    expect($type)->toBeInstanceOf(MapT::class);
    /** @var MapT $type */
    expect($type->key)->toEqual(ScalarT::string())
        ->and($type->value)->toEqual(ScalarT::int());
});

it('settles a keyed array on its key type, the same rule the docblock grammar uses', function (Type $key, string $expected): void {
    // `isList()` is only MAYBE for an int-keyed ArrayType, so it cannot be the whole answer: only a
    // string-capable-only key makes a PHP array serialize to a JSON object. Core's ArrayKey owns the
    // rule and BOTH paths call it, so `array<int, V>` cannot read two ways depending on which one found
    // it; this dataset pins the answers this path actually reaches it with.
    expect(translate(new ArrayType($key, new StringType)))->toBeInstanceOf($expected);
})->with([
    'an int key' => [new IntegerType, ListT::class],
    'an array-key key' => [new UnionType([new IntegerType, new StringType]), ListT::class],
    'a constant int key' => [new ConstantIntegerType(3), ListT::class],
    // PHPStan casts every array key to `array-key`, so a `mixed` key arrives as `int|string` and there is
    // no unreasonable key to degrade on — only a string-keyed array is left to be an object.
    'a string key' => [new StringType, MapT::class],
    'a mixed key, which PHPStan has already cast' => [new MixedType, ListT::class],
]);

it('maps a list to ListT, not to a keyed map', function (): void {
    // A `list<V>` is an int-keyed ArrayType intersected with the list accessory. Decomposing the
    // intersection first would drop that accessory and emit an object schema for a JSON array.
    $type = translate(TypeCombinator::intersect(
        new ArrayType(new IntegerType, new StringType),
        new AccessoryArrayListType,
    ));

    expect($type)->toBeInstanceOf(ListT::class);
    /** @var ListT $type */
    expect($type->value)->toEqual(ScalarT::string());
});

it('keeps the element shape of a list of array shapes', function (): void {
    $builder = ConstantArrayTypeBuilder::createEmpty();
    $builder->setOffsetValueType(new ConstantStringType('detail'), new StringType);
    $builder->setOffsetValueType(new ConstantStringType('pointer'), new StringType);

    $type = translate(TypeCombinator::intersect(
        new ArrayType(new IntegerType, $builder->getArray()),
        new AccessoryArrayListType,
    ));

    expect($type)->toBeInstanceOf(ListT::class);
    /** @var ListT $type */
    expect($type->value)->toBeInstanceOf(ArrayShapeT::class);
});

it('keeps a non-empty keyed array a MapT', function (): void {
    // Only list-ness short-circuits the intersection; other accessories still drop away.
    $type = translate(TypeCombinator::intersect(
        new ArrayType(new StringType, new IntegerType),
        new NonEmptyArrayType,
    ));

    expect($type)->toBeInstanceOf(MapT::class);
});

it('degrades to UnknownT when the depth budget is exhausted', function (): void {
    $result = (new TypeTranslator)->translate(new IntegerType, new TranslationBudget(0));

    expect($result)->toBeInstanceOf(UnknownT::class);
});

it('maps the return-only types the harvest sees on a method that answers with nothing', function (): void {
    // `void` and `never` are not payloads: one says there is no body, the other that the path never
    // returns, and both have to survive translation as themselves rather than collapse to unknown.
    expect(translate(new VoidType))->toEqual(new VoidT)
        ->and(translate(new NeverType))->toEqual(new NeverT);
});

it('translates a template parameter as the bound it is constrained to', function (): void {
    // `@template T of string` reaching the harvest unresolved: the bound is the only honest shape, and a
    // generic left untranslated would document a type name no consumer can see.
    $template = TemplateTypeFactory::create(
        TemplateTypeScope::createWithFunction('paginate'),
        'T',
        new StringType,
        TemplateTypeVariance::createInvariant(),
    );

    expect(translate($template))->toEqual(ScalarT::string());
});

it('maps a callable to CallableT, which no document ever publishes a shape for', function (): void {
    expect(translate(new CallableType))->toEqual(new CallableT);
});

it('degrades an intersection of nothing but accessory types', function (): void {
    // Accessory types (non-empty-string, literal-string) refine a type without being one, so an
    // intersection holding only those has no documentable member left — and says so rather than guessing.
    $accessories = new IntersectionType([new AccessoryNonEmptyStringType, new AccessoryLiteralStringType]);

    expect(translate($accessories))->toBeInstanceOf(UnknownT::class);
});
