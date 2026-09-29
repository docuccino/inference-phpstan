<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\TypeGrammar\TypeStringParser;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectShapeType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

/**
 * A shape is read in two places — the engine's translator from what PHPStan inferred, and the type-string
 * grammar from what an author wrote — and one written `array{…}` or `object{…}` must document the same way
 * whichever read it. The expected answer is stated here rather than asked of either reader, and the JSON
 * type it publishes is checked against what `json_encode` writes for a value of that shape: an array is
 * `[…]` exactly while its keys are `0..n` (so `[]` when it has none), an object is `{…}` whatever its keys.
 */
it('reads a shape identically from an inferred type and from a type string, as the JSON json_encode writes', function (string $written, Closure $inferred, ArrayShapeT $expected, mixed $sent): void {
    $parsed = (new TypeStringParser)->parse($written);
    $translated = (new TypeTranslator)->translate($inferred());
    $published = (new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry))->toSchema($parsed)->schema;

    expect($parsed->toArray())->toBe($expected->toArray())
        ->and($translated->toArray())->toBe($expected->toArray())
        ->and($published['type'] ?? null)->toBe(str_starts_with((string) json_encode($sent), '[') ? 'array' : 'object');
})->with([
    'an empty array' => ['array{}', static fn (): Type => new ConstantArrayType([], []), new ArrayShapeT([], isList: true), []],
    'a positional array' => [
        'array{int, string}',
        static function (): Type {
            $builder = ConstantArrayTypeBuilder::createEmpty();
            $builder->setOffsetValueType(null, new IntegerType);
            $builder->setOffsetValueType(null, new StringType);

            return $builder->getArray();
        },
        new ArrayShapeT([new ArrayShapeField(0, ScalarT::int()), new ArrayShapeField(1, ScalarT::string())], isList: true),
        [1, 'a'],
    ],
    'a keyed array' => [
        'array{id: int}',
        static fn (): Type => new ConstantArrayType([new ConstantStringType('id')], [new IntegerType]),
        new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]),
        ['id' => 1],
    ],
    'an array keyed past zero' => [
        'array{1: int}',
        static fn (): Type => new ConstantArrayType([new ConstantIntegerType(1)], [new IntegerType], [2]),
        new ArrayShapeT([new ArrayShapeField(1, ScalarT::int())]),
        [1 => 1],
    ],
    'an empty object' => ['object{}', static fn (): Type => new ObjectShapeType([], []), new ArrayShapeT([], isObject: true), new stdClass],
    'a keyed object, one optional' => [
        'object{id: int, name?: string}',
        static fn (): Type => new ObjectShapeType(['id' => new IntegerType, 'name' => new StringType], ['name']),
        new ArrayShapeT([new ArrayShapeField('id', ScalarT::int()), new ArrayShapeField('name', ScalarT::string(), optional: true)], isObject: true),
        (object) ['id' => 1],
    ],
    'an object with a numeric name' => [
        "object{'0': int}",
        static fn (): Type => new ObjectShapeType([0 => new IntegerType], []),
        new ArrayShapeT([new ArrayShapeField('0', ScalarT::int())], isObject: true),
        (object) [0 => 1],
    ],
]);
