<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Keeps one copy of phpstan/phpdoc-parser in the process. The PHPStan phar bundles its own, and the first
 * scoped class it loads registers an autoloader AHEAD of the application's that serves those same
 * `PHPStan\PhpDocParser\` names from the phar. So a class core loaded before the engine booted comes from
 * the application's copy and every later one from the phar's, and two versions of one library meet in a
 * single call. Once any class of the application's copy is loaded, the rest of it is loaded too, before
 * PHPStan boots; while none is, the choice is PHPStan's, and every class comes from its copy.
 *
 * @internal
 */
final class PhpDocParserCopy
{
    private const PREFIX = 'PHPStan\\PhpDocParser\\';

    public static function complete(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $directories = array_values(array_filter(
                array_map(static fn (string $directory): string|false => realpath($directory), $loader->getPrefixesPsr4()[self::PREFIX] ?? []),
                is_string(...),
            ));

            if ($directories !== [] && self::inPlay($directories)) {
                self::load($loader, $directories);
            }
        }
    }

    /**
     * Whether a class of this copy is already loaded.
     *
     * @param  list<string>  $directories
     */
    private static function inPlay(array $directories): bool
    {
        $loaded = array_filter(
            [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
            static fn (string $class): bool => str_starts_with($class, self::PREFIX),
        );

        return array_filter($loaded, static fn (string $class): bool => self::within((new ReflectionClass($class))->getFileName(), $directories)) !== [];
    }

    /**
     * Load every class the copy holds, each through its own loader, so a parent or an interface never
     * reaches the phar's.
     *
     * @param  list<string>  $directories
     */
    private static function load(ClassLoader $loader, array $directories): void
    {
        $own = static function (string $class) use ($loader): void {
            if (str_starts_with($class, self::PREFIX)) {
                $loader->loadClass($class);
            }
        };

        spl_autoload_register($own, prepend: true);

        try {
            foreach ($directories as $directory) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    /** @var SplFileInfo $file */
                    if ($file->getExtension() === 'php') {
                        class_exists(self::PREFIX.str_replace(DIRECTORY_SEPARATOR, '\\', substr($file->getPathname(), strlen($directory) + 1, -4)));
                    }
                }
            }
        } finally {
            spl_autoload_unregister($own);
        }
    }

    /**
     * Whether a file sits in one of the directories; composer includes `vendor/composer/../…`, so the
     * file is resolved first.
     *
     * @param  list<string>  $directories
     */
    private static function within(string|false $file, array $directories): bool
    {
        $file = $file === false ? false : realpath($file);

        return $file !== false && array_filter($directories, static fn (string $directory): bool => str_starts_with($file, $directory.DIRECTORY_SEPARATOR)) !== [];
    }
}
