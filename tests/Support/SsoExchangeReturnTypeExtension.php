<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Stands in for an extension an APPLICATION wrote and registers from its own PHPStan config: it says
 * what the fixture app's `SsoGateway::exchange()` really answers, which its bare `JsonResponse` return
 * type cannot. Loaded only by `tests/Fixtures/user-neon/extension.neon`, so it proves the user-neon
 * include rather than anything the engine ships.
 */
final class SsoExchangeReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return 'App\\Services\\SsoGateway';
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'exchange';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        return new GenericObjectType('Illuminate\\Http\\JsonResponse', [
            new ObjectType('App\\Data\\ArticleData'),
            new ConstantIntegerType(200),
        ]);
    }
}
