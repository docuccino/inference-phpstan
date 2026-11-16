<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use ReflectionProperty;

/**
 * Declares real-time facades (`Facades\App\Billing`) in memory. Laravel's alias loader writes one into the app's
 * `storage/` and then requires it, but PHPStan asks every autoloader for a class it has not loaded inside a trap
 * that serves includes empty and writes nothing, so the require fails and takes the analysis of the whole file
 * with it. The loader is relieved of those names, and each is declared as its stub would declare it.
 *
 * @internal
 */
final class RealTimeFacades
{
    private const LOADER = 'Illuminate\\Foundation\\AliasLoader';

    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';

    private const SEGMENT = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

    /**
     * @param  string  $loaderClass  the alias loader, a class whose static `$facadeNamespace` names the real-time facades
     * @return bool whether the real-time facades are now declared here rather than by the loader
     */
    public static function serve(string $loaderClass = self::LOADER): bool
    {
        if (! class_exists($loaderClass) || ! property_exists($loaderClass, 'facadeNamespace') || ! class_exists(self::FACADE)) {
            return false;
        }

        $property = new ReflectionProperty($loaderClass, 'facadeNamespace');
        $namespace = $property->getValue();
        if (! is_string($namespace) || $namespace === '') {
            return false;
        }

        // An empty namespace is the loader's own "none": it then leaves every such name to the autoloader below.
        $property->setValue(null, '');
        spl_autoload_register(static function (string $class) use ($namespace): void {
            if (str_starts_with($class, $namespace)) {
                self::declare($class, substr($class, strlen($namespace)));
            }
        });

        return true;
    }

    /** Declares `$facade` as the loader's stub does: a facade whose root is whatever the container makes of `$target`. */
    private static function declare(string $facade, string $target): void
    {
        $name = '/^'.self::SEGMENT.'(?:\\\\'.self::SEGMENT.')*$/';
        $separator = strrpos($facade, '\\');
        if ($separator === false || preg_match($name, $facade) !== 1 || preg_match($name, $target) !== 1) {
            return;
        }

        eval(sprintf(
            'namespace %s; /** @mixin \\%s */ class %s extends \\%s { protected static function getFacadeAccessor(): string { return %s; } }',
            substr($facade, 0, $separator),
            $target,
            substr($facade, $separator + 1),
            self::FACADE,
            var_export($target, true),
        ));
    }
}
