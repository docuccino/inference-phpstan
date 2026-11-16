<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Extensions\ResponseFactoryFacadeReturnTypeExtension;
use Docuccino\Inference\PhpStan\Support\ResponseFactoryCall;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeFactoryFacade;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeLedgerFacade;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeRootlessFacade;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Reflection\ReflectionProviderStaticAccessor;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VoidType;

/**
 * `Response::json($body, 422)` is `response()->json($body, 422)`: the facade hands the call to the factory it
 * resolves, so it is typed with the payload and status the factory's call carries, not the bare class the
 * facade's own `@method` tag declares. Only a facade proxying a response factory is read so.
 */
function facadeResponseType(string $code): ?Type
{
    $call = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';')[0]->expr;
    expect($call)->toBeInstanceOf(Node\Expr\StaticCall::class);

    $method = test()->createStub(MethodReflection::class);
    $method->method('getName')->willReturn($call instanceof Node\Expr\StaticCall && $call->name instanceof Node\Identifier ? $call->name->toString() : '');

    $scope = test()->createStub(Scope::class);
    $scope->method('resolveName')->willReturnCallback(static fn (Node\Name $name): string => $name->toString());
    $scope->method('getType')->willReturnCallback(static fn (Node\Expr $expr): Type => match (true) {
        // PHPStan types a receiver implementing the factory contract as a factory.
        $expr instanceof TypeExpr => $expr->getExprType() instanceof ObjectType && is_a($expr->getExprType()->getClassName(), ResponseFactoryCall::CONTRACT, true)
            ? new ObjectType(ResponseFactoryCall::CONTRACT)
            : $expr->getExprType(),
        $expr instanceof Node\Scalar\String_ => new ConstantStringType($expr->value),
        $expr instanceof Node\Scalar\Int_ => new ConstantIntegerType($expr->value),
        default => throw new RuntimeException('unexpected expression: '.$expr::class),
    });

    $reflection = test()->createStub(ReflectionProvider::class);
    $reflection->method('hasClass')->willReturnCallback(static fn (string $class): bool => class_exists($class));

    // Comparing two object types asks PHPStan's static reflection, which only a booted analyser registers;
    // one reading no class leaves any two distinct classes unrelated.
    $accessor = new ReflectionProperty(ReflectionProviderStaticAccessor::class, 'instance');
    $previous = $accessor->getValue();
    ReflectionProviderStaticAccessor::registerInstance(test()->createStub(ReflectionProvider::class));
    try {
        $extension = new ResponseFactoryFacadeReturnTypeExtension($reflection);
        if (! $call instanceof Node\Expr\StaticCall || ! $extension->isStaticMethodSupported($method)) {
            return null;
        }

        return $extension->getTypeFromStaticMethodCall($method, $call, $scope);
    } finally {
        $accessor->setValue(null, $previous);
    }
}

it('extends every facade', function (): void {
    expect((new ResponseFactoryFacadeReturnTypeExtension(test()->createStub(ReflectionProvider::class)))->getClass())
        ->toBe('Illuminate\\Support\\Facades\\Facade');
});

it('types a facade call as the same call on the response factory it proxies', function (string $code, Type $payload, int $status): void {
    $type = facadeResponseType($code);

    expect($type)->toBeInstanceOf(GenericObjectType::class)
        ->and($type?->getObjectClassNames())->toBe(['Illuminate\\Http\\JsonResponse'])
        ->and($type instanceof GenericObjectType ? $type->getTypes() : null)->toEqual([$payload, new ConstantIntegerType($status)]);
})->with([
    'json() with its status' => ['\\'.ProbeFactoryFacade::class.'::json(\'locked\', 422)', new ConstantStringType('locked'), 422],
    'json() with the framework\'s 200' => ['\\'.ProbeFactoryFacade::class.'::json(\'ok\')', new ConstantStringType('ok'), 200],
    // No body, whatever status it carries.
    'noContent() with its status' => ['\\'.ProbeFactoryFacade::class.'::noContent(205)', new VoidType, 205],
]);

it('leaves every other call on a facade to the type it declares', function (string $code): void {
    expect(facadeResponseType($code))->toBeNull();
})->with([
    'a factory method it does not type' => ['\\'.ProbeFactoryFacade::class.'::make(\'\', 410)'],
    'a facade proxying something else' => ['\\'.ProbeLedgerFacade::class.'::json(\'entry\', 422)'],
    'a facade resolving nothing' => ['\\'.ProbeRootlessFacade::class.'::json(\'locked\', 422)'],
]);
