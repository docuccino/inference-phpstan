<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Types `Resource::collection()` as the collection the called resource's `newCollection()` builds
 * ({@see ResourceCollectionType}) — the late static binding the framework's own docblock, and the bundled
 * stub standing in for it, can only answer for the default. A resource overriding `collection()` itself
 * has its own answer; one only narrowing it with a `@method` tag does not, since the framework's still runs.
 *
 * @internal
 */
final class ResourceCollectionReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    private const JSON_RESOURCE = 'Illuminate\\Http\\Resources\\Json\\JsonResource';

    private readonly ConstructedReturn $bodies;

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        $this->bodies = new ConstructedReturn;
    }

    public function getClass(): string
    {
        // An FQCN string isn't provably class-string during analysis (illuminate/* isn't a dependency
        // here); it resolves at runtime inside the host app.
        /** @phpstan-ignore return.type */
        return self::JSON_RESOURCE;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        // The method PHP runs, not the one the analyser read: a `@method` tag narrowing `collection()` still
        // calls the framework's, which still builds whatever `newCollection()` does.
        $class = $methodReflection->getDeclaringClass();

        return $methodReflection->getName() === 'collection'
            && $class->hasNativeMethod('collection')
            && $class->getNativeMethod('collection')->getDeclaringClass()->getName() === self::JSON_RESOURCE;
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        if (! $methodCall->class instanceof Name) {
            return null;
        }

        $called = $scope->resolveName($methodCall->class);

        return $this->reflectionProvider->hasClass($called)
            ? ResourceCollectionType::of($this->reflectionProvider->getClass($called), $this->bodies, $this->reflectionProvider)
            : null;
    }
}
