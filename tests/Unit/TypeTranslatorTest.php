<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Colour;
use Docuccino\Inference\PhpStan\Translation\TranslationBudget;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

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

it('degrades to UnknownT when the depth budget is exhausted', function (): void {
    $result = (new TypeTranslator)->translate(new IntegerType, new TranslationBudget(0));

    expect($result)->toBeInstanceOf(UnknownT::class);
});
