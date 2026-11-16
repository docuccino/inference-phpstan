<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

/**
 * Declares the application's class aliases (`\Response`, `\DB`, any it adds) before analysis. Laravel's
 * `AliasLoader` declares one only when it is first autoloaded, and PHPStan asks the autoloaders inside a
 * trap that serves every file it includes empty, so the facade an alias names never loads, the alias is
 * never declared, and the analyser caches it as no class at all. Declaring an alias loads its facade class
 * and nothing more: no facade's root is resolved.
 *
 * @internal
 */
final class FacadeAliases
{
    private const LOADER = 'Illuminate\\Foundation\\AliasLoader';

    /**
     * @param  string  $loaderClass  the alias loader, a class with a static `getInstance()` answering `getAliases()`
     * @return int how many aliases this declared
     */
    public static function declare(string $loaderClass = self::LOADER): int
    {
        $instance = [$loaderClass, 'getInstance'];
        if (! class_exists($loaderClass) || ! is_callable($instance)) {
            return 0;
        }

        $loader = $instance();
        $aliases = is_object($loader) && method_exists($loader, 'getAliases') ? $loader->getAliases() : [];

        $declared = 0;
        foreach (is_array($aliases) ? $aliases : [] as $alias => $class) {
            // An alias to a class that no longer exists is left to fail where the application uses it.
            if (is_string($alias) && is_string($class) && ! class_exists($alias, false) && class_exists($class)) {
                $declared += class_alias($class, $alias) ? 1 : 0;
            }
        }

        return $declared;
    }
}
