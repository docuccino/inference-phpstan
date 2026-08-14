<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Keeps the identity of a body a spatie Data object serialises itself into. `$data->transform(...)`
 * declares a bare `array`, so an app that builds its own response —
 * `new JsonResponse($this->transform(...), $status, $headers)`, the idiomatic override once the wrap or the
 * media type has to be controlled — loses the whole schema to a shapeless object even though the status and
 * headers survive the constructor fold.
 *
 * Answers the Data OBJECT rather than an expanded array shape, for the same reason as
 * {@see DataToResponseReturnTypeExtension}: what a Data class's properties look like is the adapter's spatie
 * integration's business. The transformed array and the documented schema are the same thing by
 * construction — spatie builds one from the other — so naming the object loses nothing and keeps every
 * property rule (mappers, `Optional`, `Lazy`) with the code that understands them.
 *
 * Only spatie's own `transform()` is modelled; a class that overrides it has its own answer and the refiner
 * should read that instead.
 *
 * @internal
 */
final class DataTransformReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const TRANSFORMABLE_DATA = 'Spatie\\LaravelData\\Contracts\\TransformableData';

    public function getClass(): string
    {
        // An FQCN string isn't provably class-string during analysis (spatie/laravel-data isn't a
        // dependency here); it resolves at runtime inside the host app.
        /** @phpstan-ignore return.type */
        return self::TRANSFORMABLE_DATA;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        if ($methodReflection->getName() !== 'transform') {
            return false;
        }

        // A concern-provided method reports the vendor trait's file while the class reports its own, so
        // comparing the two tells an inherited method from an override.
        $class = $methodReflection->getDeclaringClass()->getNativeReflection();

        return $class->hasMethod('transform')
            && $class->getMethod('transform')->getFileName() !== $class->getFileName();
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $subject = $scope->getType($methodCall->var);

        // Exactly one concrete class, or nothing: a union has no single documentable body, and picking one
        // of its members would be a guess — worse than the bare array left otherwise.
        return count($subject->getObjectClassNames()) === 1 ? $subject : null;
    }
}
