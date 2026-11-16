<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\CallableBody;
use Docuccino\Inference\PhpStan\Throwing\HandedException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;

/**
 * What Laravel's `throw_if($condition, $exception = 'RuntimeException', ...$parameters)` throws, stated from its
 * body: `new RuntimeException($exception)` for a string that names no class, `new $exception(...$parameters)`
 * for one that does — its default among them — and what a closure returns, read the same way. A class named
 * by a string needs the analyser's reflection, so that half is proven in the `fixture` group (`ThrowIfTest`).
 *
 * @param  (Closure(Node\Expr, Scope): ?CallableBody)|null  $closure
 * @return list<string>|null each `throw` printed, or null where the argument cannot be read
 */
function handedThrows(string $code, ?Closure $closure = null): ?array
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';') ?? [];
    $call = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Expr\FuncCall::class);
    expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class);

    $parameters = [];
    foreach (['condition', 'exception', 'parameters'] as $name) {
        $parameter = test()->createStub(ParameterReflection::class);
        $parameter->method('getName')->willReturn($name);
        $parameters[] = $parameter;
    }
    $variant = test()->createStub(ExtendedParametersAcceptor::class);
    $variant->method('getParameters')->willReturn($parameters);
    $function = test()->createStub(FunctionReflection::class);
    $function->method('getVariants')->willReturn([$variant]);

    // No string here names a class this build knows.
    $provider = test()->createStub(ReflectionProvider::class);
    $provider->method('hasClass')->willReturn(false);

    $scope = test()->createStub(Scope::class);
    $scope->method('getType')->willReturnCallback(static fn (Node\Expr $expr): Type => $expr instanceof Node\Scalar\String_
        ? new ConstantStringType($expr->value)
        : new IntegerType);

    $throws = (new HandedException($provider))->of($call, $function, $scope, $closure ?? static fn (): ?CallableBody => null);

    return $throws === null ? null : array_map(static fn (array $throw): string => (new Standard)->prettyPrintExpr($throw[0]), $throws);
}

it('reads what throw_if() throws for what it is handed', function (string $code, ?array $expected): void {
    expect(handedThrows($code))->toBe($expected);
})->with([
    // Its default is the name of `RuntimeException`, built with the parameters as any name is — by name too.
    'nothing' => ['throw_if($busy)', ['throw new \\RuntimeException()']],
    'nothing, and a parameter by name' => ['throw_if($busy, message: \'Busy.\')', ['throw new \\RuntimeException(message: \'Busy.\')']],
    // A string naming no class is the message, and the parameters go nowhere.
    'a message' => ['throw_if($busy, \'Busy.\', 409)', ['throw new \\RuntimeException(\'Busy.\')']],
    'a message by name' => ['throw_if(exception: \'Busy.\', condition: $busy)', ['throw new \\RuntimeException(\'Busy.\')']],
    // Neither an exception nor a string: Laravel would throw it, and PHP refuses, so nothing here is read.
    'a value that is no exception' => ['throw_if($busy, $status)', null],
    // A spread may hold the condition, the exception or both.
    'a spread before the exception' => ['throw_if($busy, ...$rest)', null],
    'a first-class callable' => ['throw_if(...)', null],
]);

it('reads what a closure throw_if() calls returns', function (?Node\Expr $returned, ?array $expected): void {
    $closure = static function (Node\Expr $expr, Scope $scope) use ($returned): CallableBody {
        $arrow = new Node\Expr\ArrowFunction(['expr' => $returned ?? new Node\Scalar\Int_(0)]);

        return $returned === null
            ? new CallableBody([['expr' => null, 'scope' => $scope, 'at' => $arrow]], [], [], $arrow)
            : CallableBody::ofArrow($arrow, $scope);
    };

    expect(handedThrows('throw_if($busy, fn () => 0)', $closure))->toBe($expected);
})->with([
    'a message' => [new Node\Scalar\String_('Busy.'), ['throw new \\RuntimeException(\'Busy.\')']],
    'a value that is no exception' => [new Node\Expr\Variable('status'), null],
    'nothing' => [null, null],
]);

it('reads nothing from a closure that returns nowhere', function (): void {
    $closure = static fn (): CallableBody => new CallableBody([], [], [], new Node\Expr\ArrowFunction(['expr' => new Node\Scalar\Int_(0)]));

    expect(handedThrows('throw_if($busy, function () {})', $closure))->toBeNull();
});

it('reads only Laravel\'s two throwing helpers, by PHP\'s case-insensitive name', function (string $function, bool $handles): void {
    expect(HandedException::handles($function))->toBe($handles);
})->with([
    'throw_if' => ['throw_if', true],
    'throw_unless' => ['throw_unless', true],
    'THROW_UNLESS' => ['THROW_UNLESS', true],
    // `abort_if()` builds its own exception from a status, which the throw registry reads.
    'abort_if' => ['abort_if', false],
    'report_if' => ['report_if', false],
]);
