<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Cache;

use Docuccino\Inference\PhpStan\Runtime\EngineVersion;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;

/**
 * The run-wide half of every cache key: `engine ‖ phpstan ‖ larastan ‖ generated-neon hash ‖ composer.lock
 * hash`. All of it is read off disk rather than from the running process's own vendor, so parent and worker
 * — and cold and warm runs — compute the same fingerprint, which is what makes byte-identical cache hits
 * possible. {@see FilesystemEngineResultCache} adds the per-entry half at store/lookup time.
 *
 * @internal
 */
final readonly class VersionFingerprint
{
    public function __construct(
        public string $engineVersion,
        public string $phpstanVersion,
        public string $larastanVersion,
        public string $neonHash,
        public string $composerLockHash,
    ) {}

    /**
     * Versions come from the app's `composer.lock`, not the running process's vendor, so parent and worker
     * agree.
     */
    public static function forRuntime(RuntimeConfig $config): self
    {
        $composerLock = $config->projectRoot.'/composer.lock';
        $lockContents = is_file($composerLock) ? (string) file_get_contents($composerLock) : '';

        return new self(
            engineVersion: EngineVersion::ID,
            phpstanVersion: self::lockedVersion($lockContents, 'phpstan/phpstan'),
            larastanVersion: self::lockedVersion($lockContents, 'larastan/larastan'),
            neonHash: self::neonHash($config),
            composerLockHash: hash('sha256', $lockContents),
        );
    }

    /** The stable prefix hashed into every per-entry key. */
    public function prefix(): string
    {
        return implode("\0", [
            $this->engineVersion,
            $this->phpstanVersion,
            $this->larastanVersion,
            $this->neonHash,
            $this->composerLockHash,
        ]);
    }

    private static function neonHash(RuntimeConfig $config): string
    {
        $larastanNeon = $config->resolvedLarastanNeon();
        $userNeon = $config->userNeon;

        $material = implode("\0", [
            is_file($larastanNeon) ? (string) file_get_contents($larastanNeon) : '',
            $userNeon !== null && is_file($userNeon) ? (string) file_get_contents($userNeon) : '',
            (string) $config->phpVersion,
            implode(',', $config->projectPaths),
        ]);

        return hash('sha256', $material);
    }

    private static function lockedVersion(string $lockContents, string $package): string
    {
        if ($lockContents === '') {
            return 'unknown';
        }

        /** @var array{packages?: list<array{name?: string, version?: string}>, 'packages-dev'?: list<array{name?: string, version?: string}>} $lock */
        $lock = json_decode($lockContents, true, flags: JSON_THROW_ON_ERROR) ?: [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $entry) {
                if (($entry['name'] ?? null) === $package) {
                    return (string) ($entry['version'] ?? 'unknown');
                }
            }
        }

        return 'unknown';
    }
}
