<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Inference\PhpStan\Support\PlainContent;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Reflection\ReflectionProviderStaticAccessor;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * What a plain response sends for its content is what Laravel's `Response::setContent()` does with it, as
 * the wire shows: `''`, `null` or nothing is an empty body; an array, Arrayable, Jsonable, ArrayObject,
 * JsonSerializable or stdClass is morphed to JSON and labelled `application/json`; a Renderable is rendered;
 * any other scalar is sent as it is. Symfony's own response morphs nothing, so an array there says nothing.
 */
function plainContentOf(?Type $content, bool $morphs = true): string
{
    return plainContentReading(static fn (): string => PlainContent::of($content, $morphs));
}

/**
 * @template T
 *
 * @param  Closure(): T  $read
 * @return T
 */
function plainContentReading(Closure $read): mixed
{
    // Comparing object types asks PHPStan's static reflection, which only a booted analyser registers; one
    // reading no class still knows a class is itself.
    $accessor = new ReflectionProperty(ReflectionProviderStaticAccessor::class, 'instance');
    $previous = $accessor->getValue();
    ReflectionProviderStaticAccessor::registerInstance(test()->createStub(ReflectionProvider::class));
    try {
        return $read();
    } finally {
        $accessor->setValue(null, $previous);
    }
}

it('reads content as Laravel sends it', function (?Type $content, string $sent): void {
    expect(plainContentOf($content))->toBe($sent);
})->with([
    // Nothing is no body: Laravel sends an empty one.
    'an empty string' => [new ConstantStringType(''), PlainContent::EMPTY],
    'null' => [new NullType, PlainContent::EMPTY],
    'either of them' => [new UnionType([new ConstantStringType(''), new NullType]), PlainContent::EMPTY],
    // A string is sent as it is, and so is a number PHP casts to one.
    'a string' => [new ConstantStringType('archived'), PlainContent::TEXT],
    'any string' => [new StringType, PlainContent::TEXT],
    'a string or nothing' => [new UnionType([new StringType, new NullType]), PlainContent::TEXT],
    'an int' => [new ConstantIntegerType(42), PlainContent::TEXT],
    'a float' => [new ConstantFloatType(1.5), PlainContent::TEXT],
    // An array is morphed to JSON.
    'an array' => [new ArrayType(new MixedType, new MixedType), PlainContent::JSON],
    'an array literal' => [new ConstantArrayType([new ConstantStringType('error')], [new ConstantStringType('locked')]), PlainContent::JSON],
    // …and so is every object `shouldBeJson()` names.
    'an Arrayable' => [new ObjectType('Illuminate\\Contracts\\Support\\Arrayable'), PlainContent::JSON],
    'a Jsonable' => [new ObjectType('Illuminate\\Contracts\\Support\\Jsonable'), PlainContent::JSON],
    'an ArrayObject' => [new ObjectType('ArrayObject'), PlainContent::JSON],
    'a JsonSerializable' => [new ObjectType('JsonSerializable'), PlainContent::JSON],
    'a stdClass' => [new ObjectType('stdClass'), PlainContent::JSON],
    // A Renderable is rendered.
    'a Renderable' => [new ObjectType('Illuminate\\Contracts\\Support\\Renderable'), PlainContent::RENDERED],
    // Anything that may be more than one of those proves none of them.
    'mixed' => [new MixedType, PlainContent::UNREAD],
    'a string or an array' => [new UnionType([new StringType, new ArrayType(new MixedType, new MixedType)]), PlainContent::UNREAD],
    'any other object' => [new ObjectType('DateTimeImmutable'), PlainContent::UNREAD],
    'content a spread holds' => [null, PlainContent::UNREAD],
]);

it('morphs nothing for Symfony\'s own response', function (Type $content, string $sent): void {
    expect(plainContentOf($content, morphs: false))->toBe($sent);
})->with([
    // Symfony takes a string; an array is a type error there, not JSON.
    'an array' => [new ArrayType(new MixedType, new MixedType), PlainContent::UNREAD],
    'a JsonSerializable' => [new ObjectType('JsonSerializable'), PlainContent::UNREAD],
    'a Renderable' => [new ObjectType('Illuminate\\Contracts\\Support\\Renderable'), PlainContent::UNREAD],
    'a string' => [new ConstantStringType('archived'), PlainContent::TEXT],
    'an empty string' => [new ConstantStringType(''), PlainContent::EMPTY],
]);

it('sends text as the one string it folds to, or as any string', function (Type $content, DType $body): void {
    expect(PlainContent::text($content))->toEqual($body);
})->with([
    'a string' => [new ConstantStringType('archived'), new LiteralT('archived')],
    // PHP casts a number to the string Laravel sends.
    'an int' => [new ConstantIntegerType(42), new LiteralT('42')],
    'any string' => [new StringType, ScalarT::string()],
    'one of two strings' => [new UnionType([new ConstantStringType('a'), new ConstantStringType('b')]), ScalarT::string()],
    'any int' => [new IntegerType, ScalarT::string()],
    // Content that may be null may be no body at all, so no one string is what it always sends.
    'a string or nothing' => [new UnionType([new ConstantStringType('archived'), new NullType]), ScalarT::string()],
]);

it('reads content of no one kind as possibly JSON only where Laravel may morph it', function (?Type $content, bool $morphs, bool $json): void {
    expect(plainContentReading(static fn (): bool => PlainContent::mayBeJson($content, $morphs)))->toBe($json);
})->with([
    // `setContent()` morphs an array or anything `shouldBeJson()` names, relabelling it `application/json`
    // over a stated header; content that may be one of those may be sent as JSON or as text.
    'mixed' => [new MixedType, true, true],
    'a string or an array' => [new UnionType([new StringType, new ArrayType(new MixedType, new MixedType)]), true, true],
    'a string or a JsonSerializable' => [new UnionType([new StringType, new ObjectType('JsonSerializable')]), true, true],
    'content a spread holds' => [null, true, true],
    // Content that can be none of them is never morphed, so it keeps the label it is sent with.
    'a string or false' => [new UnionType([new StringType, new ConstantBooleanType(false)]), true, false],
    'a string, false or nothing' => [new UnionType([new StringType, new ConstantBooleanType(false), new NullType]), true, false],
    // Symfony's own response takes only a string, so nothing it sends is JSON.
    'mixed, to Symfony' => [new MixedType, false, false],
    'content a spread holds, to Symfony' => [null, false, false],
]);
