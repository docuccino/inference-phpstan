<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use ReflectionEnum;
use ReflectionEnumUnitCase;
use Throwable;

/**
 * An enum's case names by reflection, shared by the scope-driven type translator and the reflection-driven
 * native type mapper so the two can't drift.
 *
 * @internal
 */
final class EnumCases
{
    /**
     * @return list<string> the enum's case names, or `[]` if not an enum / unreadable
     */
    public static function names(string $className): array
    {
        if (! enum_exists($className)) {
            return [];
        }

        try {
            $reflection = new ReflectionEnum($className);

            return array_values(array_map(
                static fn (ReflectionEnumUnitCase $case): string => $case->getName(),
                $reflection->getCases(),
            ));
        } catch (Throwable) {
            return [];
        }
    }
}
