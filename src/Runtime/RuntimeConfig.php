<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

/** Everything an adapter needs to boot a PHPStan container against a host Laravel app. */
final readonly class RuntimeConfig
{
    /**
     * @param  string  $projectRoot  the Laravel app root (becomes cwd; Larastan boots the app from here)
     * @param  string  $tmpDir  PHPStan's compiled-container + result-cache dir; MUST be isolated per invocation
     * @param  int  $phpVersion  PHP_VERSION_ID-style integer, e.g. 80500
     * @param  list<string>  $projectPaths  directories whose `.php` files are primed up front and treated as project code
     * @param  string|null  $larastanNeon  absolute path to `larastan/extension.neon`; auto-detected under $projectRoot/vendor when null
     * @param  string|null  $userNeon  optional user `docuccino.neon` merged into the generated config
     * @param  list<string>  $autoloaderProjectPaths  passed to ContainerFactory so PHPStan can reflect the app's classes
     */
    public function __construct(
        public string $projectRoot,
        public string $tmpDir,
        public int $phpVersion,
        public array $projectPaths,
        public ?string $larastanNeon = null,
        public ?string $userNeon = null,
        public array $autoloaderProjectPaths = [],
    ) {}

    public function resolvedLarastanNeon(): string
    {
        return $this->larastanNeon
            ?? $this->projectRoot.'/vendor/larastan/larastan/extension.neon';
    }

    /**
     * @return list<string>
     */
    public function resolvedAutoloaderPaths(): array
    {
        return $this->autoloaderProjectPaths === []
            ? [$this->projectRoot]
            : $this->autoloaderProjectPaths;
    }
}
