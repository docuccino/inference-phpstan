<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\GuardCondition;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Type;

/**
 * `abort_if()` and `throw_if()` throw on a truthy condition, `abort_unless()` and `throw_unless()` on a falsy
 * one — Laravel's own `if ($condition)` and `if (! $condition)`. A condition whose native type is always the
 * other value never throws; one that may be either may throw.
 */
function guardNeverThrows(string $code, string $function, ?Type $native = null): bool
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';') ?? [];
    $call = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Expr\FuncCall::class);
    expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class);

    $parameters = [];
    foreach (['condition', 'exception'] as $name) {
        $parameter = test()->createStub(ParameterReflection::class);
        $parameter->method('getName')->willReturn($name);
        $parameters[] = $parameter;
    }
    $variant = test()->createStub(ExtendedParametersAcceptor::class);
    $variant->method('getParameters')->willReturn($parameters);
    $reflection = test()->createStub(FunctionReflection::class);
    $reflection->method('getName')->willReturn($function);
    $reflection->method('getVariants')->willReturn([$variant]);

    $scope = test()->createStub(Scope::class);
    $scope->method('getNativeType')->willReturnCallback(static fn (Node\Expr $expr): Type => $native ?? match (true) {
        $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'true' => new ConstantBooleanType(true),
        $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'false' => new ConstantBooleanType(false),
        default => new BooleanType,
    });

    return GuardCondition::neverThrows($reflection, $call, $scope);
}

it('reads a guard handed the condition it does not throw on as never throwing', function (string $code, string $function, bool $never): void {
    expect(guardNeverThrows($code, $function))->toBe($never);
})->with([
    'abort_if(false)' => ['abort_if(false, 403)', 'abort_if', true],
    'abort_if(true)' => ['abort_if(true, 403)', 'abort_if', false],
    'abort_unless(true)' => ['abort_unless(true, 403)', 'abort_unless', true],
    'abort_unless(false)' => ['abort_unless(false, 403)', 'abort_unless', false],
    'throw_if(false)' => ['throw_if(false, $e)', 'throw_if', true],
    'throw_unless(true)' => ['throw_unless(true, $e)', 'throw_unless', true],
    // The name is Laravel's, matched as PHP matches a function's: case-insensitively.
    'THROW_IF(false)' => ['THROW_IF(false, $e)', 'THROW_IF', true],
    // A condition chosen at run time may be either value.
    'a condition that may be either' => ['throw_if($flag, $e)', 'throw_if', false],
    // The condition is the first parameter wherever it is written.
    'the condition by name' => ['throw_if(exception: $e, condition: false)', 'throw_if', true],
]);

it('reads no settled condition where there is none to read', function (string $code, string $function): void {
    expect(guardNeverThrows($code, $function, new ConstantBooleanType(false)))->toBeFalse();
})->with([
    // `abort()` has no condition: whatever its first argument is, it throws.
    'a thrower with no condition' => ['abort(false)', 'abort'],
    'no condition written' => ['throw_if()', 'throw_if'],
    'a condition behind a spread' => ['throw_if(...$arguments)', 'throw_if'],
    'a first-class callable' => ['throw_if(...)', 'throw_if'],
]);
