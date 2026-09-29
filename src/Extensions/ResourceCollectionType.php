<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;

/**
 * The collection `Resource::collection()` builds, which is whatever the called resource's
 * `newCollection()` returns: `collection()` calls `static::newCollection()`, so an override anywhere in the
 * resource's hierarchy decides the class, and its `with()` and `$wrap` decide the response.
 *
 * The class is the one the override's body constructs where that is not what its type says
 * ({@see ConstructedReturn}): an override with no return type, as the framework writes its own, states the
 * framework's collection through the docblock it inherits, whatever collection the body builds.
 *
 * An anonymous collection carries the resource it collects as its first type argument, the slot every
 * reader of one reads the items from, including a subclass that declares no template of its own. Where
 * the override's type names no resource, it is the called one — the one `newCollection()` is handed as
 * `static::class`. Null where the framework's own `newCollection()` runs: the bundled stub already answers
 * that, and the JSON:API resource's is modelled by its own document.
 *
 * @internal
 */
final class ResourceCollectionType
{
    private const JSON_RESOURCE = 'Illuminate\\Http\\Resources\\Json\\JsonResource';

    private const JSON_API_RESOURCE = 'Illuminate\\Http\\Resources\\JsonApi\\JsonApiResource';

    private const ANONYMOUS_COLLECTION = 'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection';

    public static function of(ClassReflection $resource, ConstructedReturn $bodies, ReflectionProvider $reflectionProvider): ?Type
    {
        if (! $resource->is(self::JSON_RESOURCE) || ! $resource->hasNativeMethod('newCollection')) {
            return null;
        }

        $newCollection = $resource->getNativeMethod('newCollection');
        $declaring = $newCollection->getDeclaringClass()->getName();
        if ($declaring === self::JSON_RESOURCE || $declaring === self::JSON_API_RESOURCE) {
            return null;
        }

        $returned = self::built(self::bound($newCollection->getOnlyVariant()->getReturnType(), $resource), $resource, $bodies, $reflectionProvider);

        $classes = $returned->getObjectClassReflections();
        if (count($classes) !== 1 || ! $classes[0]->is(self::ANONYMOUS_COLLECTION)) {
            // A named collection names what it collects itself; anything else is the override's own answer.
            return $returned;
        }

        $collection = $classes[0];
        if (count($collection->getTemplateTypeMap()->getTypes()) > 1) {
            return $returned;
        }

        // The template the bundled stub gives the framework's collection, as the override's type binds it.
        $declared = $collection->getAncestorWithClassName(self::ANONYMOUS_COLLECTION)?->getActiveTemplateTypeMap()->getType('TResource');
        $item = $declared !== null && self::isResource($declared) ? $declared : new ObjectType($resource->getName());

        return new GenericObjectType($collection->getName(), [$item]);
    }

    /**
     * The class the override's body builds wherever it is not simply the class the type states. Not only
     * where it is narrower: an override with no return type states the framework's anonymous collection
     * through the docblock it inherits, and a named collection it builds is no subtype of that.
     */
    private static function built(Type $returned, ClassReflection $resource, ConstructedReturn $bodies, ReflectionProvider $reflectionProvider): Type
    {
        $built = $bodies->of($resource->getNativeReflection()->getMethod('newCollection'));
        if ($built === null || ! $reflectionProvider->hasClass($built)) {
            return $returned;
        }

        $class = $reflectionProvider->getClass($built);
        $stated = $returned->getObjectClassNames();

        return $stated !== [$class->getName()] ? new ObjectType($class->getName()) : $returned;
    }

    /** `static` in the override's type, bound to the class `collection()` was called on. */
    private static function bound(Type $type, ClassReflection $resource): Type
    {
        return TypeTraverser::map($type, static function (Type $type, callable $traverse) use ($resource): Type {
            // @phpstan-ignore phpstanApi.instanceofType (a late-static-bound type has no other accessor to bind it by)
            if ($type instanceof StaticType) {
                return $type->changeBaseClass($resource)->getStaticObjectType();
            }

            return $traverse($type);
        });
    }

    private static function isResource(Type $type): bool
    {
        $classes = $type->getObjectClassReflections();

        return count($classes) === 1 && $classes[0]->is(self::JSON_RESOURCE);
    }
}
