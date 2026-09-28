<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerRangeType;
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

it('folds a status to every constant code it can be', function (Type $type, array $codes): void {
    // `$ok ? 200 : 503` is two statuses the server can send, and each is owed a response. One literal
    // folds exactly as `of()` folds it, so the two readers never disagree about a single code.
    expect(ScalarFold::ints($type))->toBe($codes);
})->with([
    'one literal' => [new ConstantIntegerType(202), [202]],
    'a choice of two' => [TypeCombinator::union(new ConstantIntegerType(503), new ConstantIntegerType(200)), [200, 503]],
    'a choice of three, once each' => [TypeCombinator::union(new ConstantIntegerType(201), new ConstantIntegerType(200), new ConstantIntegerType(201), new ConstantIntegerType(409)), [200, 201, 409]],
]);

it('folds no status from a type that is not all constant codes', function (Type $type): void {
    expect(ScalarFold::ints($type))->toBeNull();
})->with([
    'a general int' => [new IntegerType],
    // A range is inferred, not written: expanding it would publish codes nobody named.
    'an inferred range' => [IntegerRangeType::fromInterval(200, 204)],
    'a code or a string' => [TypeCombinator::union(new ConstantIntegerType(200), new ConstantStringType('200'))],
    'a code or null' => [TypeCombinator::union(new ConstantIntegerType(200), new NullType)],
    'a string' => [new ConstantStringType('200')],
    'a float' => [new ConstantFloatType(200.0)],
    'mixed' => [new MixedType],
]);

it('carries folded codes as one literal, or as the union of one literal each', function (): void {
    expect(ScalarFold::status([202]))->toEqual(new LiteralT(202))
        ->and(ScalarFold::status([200, 503]))->toEqual(UnionT::of([new LiteralT(200), new LiteralT(503)]));
});

it('folds a status type straight to what a response carries', function (): void {
    expect(ScalarFold::statusOf(new ConstantIntegerType(201)))->toEqual(new LiteralT(201))
        ->and(ScalarFold::statusOf(TypeCombinator::union(new ConstantIntegerType(503), new ConstantIntegerType(200))))
        ->toEqual(UnionT::of([new LiteralT(200), new LiteralT(503)]))
        ->and(ScalarFold::statusOf(new IntegerType))->toBeNull()
        ->and(ScalarFold::statusOf(null))->toBeNull();
});
