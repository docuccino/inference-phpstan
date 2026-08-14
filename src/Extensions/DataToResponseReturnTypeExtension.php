<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;

/**
 * Keeps the body a spatie Data object renders itself into. `$data->toResponse($request)` declares a bare
 * `JsonResponse`, so an app whose exception renderer (or controller) goes through it loses the entire
 * response shape before the pipeline sees it — status and media type survive, the payload doesn't.
 * Re-attaches it as `JsonResponse<TheDataClass>`, paired with the bundled `JsonResponse.stub`.
 *
 * The payload stays the Data OBJECT rather than an expanded shape. Reading a Data class's properties,
 * name mappers and `Optional`/`Lazy` members is the adapter's spatie integration's job, and this package
 * has no business knowing any of it; handing back a class type keeps that boundary intact — the engine
 * says "this response carries that object", the adapter says what the object looks like.
 *
 * No status is attached, deliberately. `calculateResponseStatus()` is request-dependent (spatie's own
 * default reads the HTTP verb), so there is nothing statically foldable to attach, and the pipeline's
 * fallback — the thrown exception's status hint — is the real answer on the error path.
 *
 * Targets the CONTRACT, not `Data`: every `Data`, `Resource` and collectable implements it, and an
 * extension aimed at one concrete class would silently miss the others.
 *
 * @internal
 */
final class DataToResponseReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const RESPONSABLE_DATA = 'Spatie\\LaravelData\\Contracts\\ResponsableData';

    private const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    public function getClass(): string
    {
        // An FQCN string isn't provably class-string during analysis (spatie/laravel-data isn't a
        // dependency here); it resolves at runtime inside the host app.
        /** @phpstan-ignore return.type */
        return self::RESPONSABLE_DATA;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'toResponse' && self::isSpatieOwn($methodReflection);
    }

    /**
     * Only spatie's own `toResponse()` is modelled. A class that overrides it has written the response
     * itself — typically `new JsonResponse(...)` with real headers and a real status — and the refiner's
     * constructor fold reads far more from that than this extension could say. Claiming the type here would
     * short-circuit it and cost the media type and status the app spelled out.
     *
     * The concern-provided method reports the vendor trait's file while the class reports its own, so
     * comparing the two tells an inherited method from an override.
     */
    private static function isSpatieOwn(MethodReflection $methodReflection): bool
    {
        $class = $methodReflection->getDeclaringClass()->getNativeReflection();

        if (! $class->hasMethod('toResponse')) {
            return false;
        }

        return $class->getMethod('toResponse')->getFileName() !== $class->getFileName();
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $subject = $scope->getType($methodCall->var);

        // Exactly one concrete class, or nothing: a union of Data classes has no single documentable body,
        // and picking one of them would be a guess — worse than the bare JsonResponse left otherwise.
        if (count($subject->getObjectClassNames()) !== 1) {
            return null;
        }

        return new GenericObjectType(self::JSON_RESPONSE, [$subject]);
    }
}
