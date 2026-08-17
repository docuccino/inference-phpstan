<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\ScalarFold;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * The one place a PHPStan type is reduced to a single documentable scalar — a status, a media type, a
 * pinned member value. Every kind it folds, and every kind it must refuse, since a value folded out of a
 * type that holds two of them would publish one branch's number for both.
 */
it('folds a type that holds exactly one scalar value', function (Type $type, string|int|float|bool|null $value): void {
    expect(ScalarFold::of($type))->toBe([$value]);
})->with([
    'a constant string' => [new ConstantStringType('application/problem+json'), 'application/problem+json'],
    'a constant int' => [new ConstantIntegerType(422), 422],
    'a constant float' => [new ConstantFloatType(1.5), 1.5],
    'a constant bool' => [new ConstantBooleanType(true), true],
    // `null` is a constant scalar value of its own, and folding it says so; whether a folded null is
    // documentable is the caller's question.
    'null' => [new NullType, null],
]);

it('refuses a type that does not name one value', function (Type $type): void {
    expect(ScalarFold::of($type))->toBeNull();
})->with([
    'a general int' => [new IntegerType],
    'mixed' => [new MixedType],
    'two constant ints' => [TypeCombinator::union(new ConstantIntegerType(200), new ConstantIntegerType(202))],
    'two constant strings' => [TypeCombinator::union(new ConstantStringType('a'), new ConstantStringType('b'))],
]);
