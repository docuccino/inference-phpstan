<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;

/**
 * Whether a value can render as "leave this key out" rather than as a value — spatie's `Optional`/`Lazy`
 * markers, what an app writes as `$x ?? new Optional`. A member whose value may be one is in the body on
 * some responses and gone from others, which is a different fact from "this branch supplies it": the
 * key is not there for a client to read.
 *
 * The test is on the TYPE, so it holds however the marker got there — a coalesce, a ternary, a variable
 * already typed `string|Optional` — and PHPStan narrowing the marker away (a left side it proved non-null)
 * settles the member as present, which is the answer we want.
 *
 * @internal
 */
final class OmissionSentinel
{
    /** The markers spatie renders as an absent key. Subclasses count: `Lazy` is abstract. */
    private const MARKERS = [
        'Spatie\\LaravelData\\Optional',
        'Spatie\\LaravelData\\Lazy',
    ];

    /** True when any arm of the type is one. A type with no object arm never is. */
    public static function inType(Type $type): bool
    {
        foreach (TypeUtils::flattenTypes($type) as $arm) {
            foreach ($arm->getObjectClassNames() as $class) {
                foreach (self::MARKERS as $marker) {
                    if (is_a($class, $marker, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
