<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use ReflectionClass;

/**
 * Whether a class still runs the body a vendor TRAIT supplies for a method, or has had it replaced. The
 * two Data extensions both model spatie's own body and must decline the moment somebody else's body is
 * what runs, because the refiner reads far more out of a hand-written response than either can claim.
 *
 * Inheritance answers itself: an override on a base class is reported as declared by that base class, so
 * its method and its class agree on a file. A trait does not — PHP flattens the body in while reflection
 * still names the trait's file, so a class that took `toResponse()` from an application's trait looked
 * exactly like one that took it from spatie's concern. Naming the concern is what separates them.
 *
 * Every path here is read through the reflection that was handed in, never through PHP's own: the
 * analyser spells a vendor file as composer's autoloader recorded it (`vendor/composer/../spatie/…`) and
 * PHP spells it canonically, so one file compared across the two is never equal.
 *
 * @internal
 */
final class VendorConcern
{
    /**
     * @param  ReflectionClass<object>  $class  the class the method was resolved on
     * @param  string  $trait  FQCN of the vendor trait that declares the default body
     */
    public static function provides(ReflectionClass $class, string $trait, string $method): bool
    {
        if (! $class->hasMethod($method)) {
            return false;
        }

        $reflection = $class->getMethod($method);
        $file = $reflection->getFileName();

        // The declaring class is the one that `use`d the trait; its own file is not what we compare.
        return $file !== false && $file === self::traitFile($reflection->getDeclaringClass(), $trait);
    }

    /**
     * The named trait's file, as this reflection reports it, or null if the class does not use it.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function traitFile(ReflectionClass $class, string $trait): ?string
    {
        foreach ($class->getTraits() as $used) {
            if ($used->getName() === $trait) {
                $file = $used->getFileName();

                return $file === false ? null : $file;
            }

            // A trait can be composed of traits, and then the body's file is the inner one's.
            $nested = self::traitFile($used, $trait);

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }
}
