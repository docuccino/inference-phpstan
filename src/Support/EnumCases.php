<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use ReflectionEnum;
use ReflectionEnumUnitCase;
use Throwable;

/**
 * Reads an enum's case names via reflection. Shared by the type translator
 * (scope-driven) and the native type mapper (reflection-driven), which
 * previously carried byte-identical private copies.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
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
