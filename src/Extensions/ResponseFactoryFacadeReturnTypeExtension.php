<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use Docuccino\Inference\PhpStan\Support\FacadeCall;
use Docuccino\Inference\PhpStan\Support\ResponseFactoryCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Types `Response::json(…)` and `Response::noContent(…)` as {@see ResponseJsonReturnTypeExtension} types the
 * same call on the factory, for any facade whose root is a response factory ({@see FacadeCall}). The facade's
 * own `@method` tags declare the bare class, which drops the payload and the status.
 *
 * @internal
 */
final class ResponseFactoryFacadeReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';

    private readonly ResponseJsonReturnTypeExtension $factory;

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        $this->factory = new ResponseJsonReturnTypeExtension;
    }

    public function getClass(): string
    {
        // An FQCN string isn't provably class-string during analysis (illuminate/* isn't a dependency
        // here); it resolves at runtime inside the host app.
        /** @phpstan-ignore return.type */
        return self::FACADE;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $this->factory->isMethodSupported($methodReflection);
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $call = FacadeCall::onRoot($methodCall, $scope, $this->reflectionProvider);
        if ($call === null || ! (new ObjectType(ResponseFactoryCall::CONTRACT))->isSuperTypeOf($scope->getType($call->var))->yes()) {
            return null;
        }

        return $this->factory->getTypeFromMethodCall($methodReflection, $call, $scope);
    }
}
