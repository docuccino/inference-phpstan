<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Extensions\ResponseJsonReturnTypeExtension;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\VoidType;
use PHPUnit\Framework\MockObject\Stub;

/**
 * The extension re-attaches a `response()->json()` call's payload and status to the type it hands back, and
 * both are read off a POSITION. A spread fills its own position and every later one, so a status that looks
 * absent may well have been passed — and the framework's default is only true of a call that provably
 * passed none.
 */
function jsonMethod(Stub&MethodReflection $reflection, string $name): MethodReflection
{
    $reflection->method('getName')->willReturn($name);

    return $reflection;
}

/** A scope that types a string literal as itself and an int literal as itself; anything else is a failure. */
function jsonScope(Stub&Scope $scope): Scope
{
    $scope->method('getType')->willReturnCallback(static fn (Node\Expr $expr): Type => match (true) {
        $expr instanceof Node\Scalar\String_ => new ConstantStringType($expr->value),
        $expr instanceof Node\Scalar\Int_ => new ConstantIntegerType($expr->value),
        default => throw new RuntimeException('unexpected expression: '.$expr::class),
    });

    return $scope;
}

/** `$factory-><method>(<args>)`, built the way php-parser hands one to the extension. */
function jsonCall(string $method, array $args): Node\Expr\MethodCall
{
    return new Node\Expr\MethodCall(new Node\Expr\Variable('factory'), new Node\Identifier($method), $args);
}

it('declines a first-class callable rather than handing a placeholder to the scope', function (): void {
    // `getArgs()` only ASSERTS there is no placeholder in there; under `zend.assertions=-1` it hands one
    // back, and a placeholder is not an expression the scope can be asked about.
    $type = (new ResponseJsonReturnTypeExtension)->getTypeFromMethodCall(
        jsonMethod($this->createStub(MethodReflection::class), 'json'),
        jsonCall('json', [new Node\VariadicPlaceholder]),
        jsonScope($this->createStub(Scope::class)),
    );

    expect($type)->toBeNull();
});

it('says nothing at all about a json() whose payload may be inside a spread', function (): void {
    // Typing the spread expression here documents the ARGUMENT LIST as the response body.
    $type = (new ResponseJsonReturnTypeExtension)->getTypeFromMethodCall(
        jsonMethod($this->createStub(MethodReflection::class), 'json'),
        jsonCall('json', [new Node\Arg(new Node\Expr\Variable('args'), unpack: true)]),
        jsonScope($this->createStub(Scope::class)),
    );

    expect($type)->toBeNull();
});

it('keeps a payload it can read while widening a status the spread may carry', function (): void {
    $type = (new ResponseJsonReturnTypeExtension)->getTypeFromMethodCall(
        jsonMethod($this->createStub(MethodReflection::class), 'json'),
        jsonCall('json', [
            new Node\Arg(new Node\Scalar\String_('body')),
            new Node\Arg(new Node\Expr\Variable('rest'), unpack: true),
        ]),
        jsonScope($this->createStub(Scope::class)),
    );

    expect($type)->toBeInstanceOf(GenericObjectType::class)
        ->and($type->getTypes()[0])->toEqual(new ConstantStringType('body'))
        ->and($type->getTypes()[1])->toEqual(new IntegerType);
});

it('takes the framework default only for a call that provably passed no status', function (string $method, array $args, Type $expected): void {
    $type = (new ResponseJsonReturnTypeExtension)->getTypeFromMethodCall(
        jsonMethod($this->createStub(MethodReflection::class), $method),
        jsonCall($method, $args),
        jsonScope($this->createStub(Scope::class)),
    );

    expect($type)->toBeInstanceOf(GenericObjectType::class)
        ->and($type->getTypes()[1])->toEqual($expected);
})->with([
    'json with nothing after the payload' => [
        'json', [new Node\Arg(new Node\Scalar\String_('body'))], new ConstantIntegerType(200),
    ],
    'json with the status written' => [
        'json',
        [new Node\Arg(new Node\Scalar\String_('body')), new Node\Arg(new Node\Scalar\Int_(201))],
        new ConstantIntegerType(201),
    ],
    'json with the status named' => [
        'json',
        [new Node\Arg(new Node\Scalar\String_('body')), new Node\Arg(new Node\Scalar\Int_(201), name: new Node\Identifier('status'))],
        new ConstantIntegerType(201),
    ],
    'noContent with nothing at all' => ['noContent', [], new ConstantIntegerType(204)],
    'noContent with the status written' => ['noContent', [new Node\Arg(new Node\Scalar\Int_(205))], new ConstantIntegerType(205)],
    // The empty body is true of every noContent, whatever status a spread may be carrying; only the
    // status widens.
    'noContent whose status may be in a spread' => [
        'noContent', [new Node\Arg(new Node\Expr\Variable('args'), unpack: true)], new IntegerType,
    ],
]);

it('keeps the void payload that marks a noContent as bodiless', function (): void {
    $type = (new ResponseJsonReturnTypeExtension)->getTypeFromMethodCall(
        jsonMethod($this->createStub(MethodReflection::class), 'noContent'),
        jsonCall('noContent', [new Node\Arg(new Node\Expr\Variable('args'), unpack: true)]),
        jsonScope($this->createStub(Scope::class)),
    );

    expect($type)->toBeInstanceOf(GenericObjectType::class)
        ->and($type->getTypes()[0])->toEqual(new VoidType);
});
