<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Types `$items->toResourceCollection(Resource::class)` as the collection that resource's `newCollection()`
 * builds ({@see ResourceCollectionType}): the framework's body is `Resource::collection($this)`, so it
 * reaches the same override. Only the framework's own body, on a collection or a paginator, and only for a
 * resource the call names; the argument-less guessing form keeps the bundled stub's answer.
 *
 * @internal
 */
final class ResourceCollectionTransformReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /** Every class using the framework's trait implements it, and the trait is what supportability checks. */
    private const ARRAYABLE = 'Illuminate\\Contracts\\Support\\Arrayable';

    private const TRANSFORMS_TO_RESOURCE_COLLECTION = 'Illuminate\\Support\\Traits\\TransformsToResourceCollection';

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
        return self::ARRAYABLE;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'toResourceCollection'
            && VendorConcern::provides(
                $methodReflection->getDeclaringClass()->getNativeReflection(),
                self::TRANSFORMS_TO_RESOURCE_COLLECTION,
                'toResourceCollection',
            );
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $argument = $methodCall->getArgs()[0] ?? null;
        if ($argument === null || $argument->unpack) {
            return null;
        }

        // Exactly one named resource, or the stub's answer: a class chosen at runtime has no one override.
        $named = $scope->getType($argument->value)->getConstantStrings();
        if (count($named) !== 1 || ! $this->reflectionProvider->hasClass($named[0]->getValue())) {
            return null;
        }

        return ResourceCollectionType::of($this->reflectionProvider->getClass($named[0]->getValue()), $this->bodies, $this->reflectionProvider);
    }
}
