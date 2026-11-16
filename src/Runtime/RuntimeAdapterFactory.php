<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use Composer\InstalledVersions;
use Docuccino\Inference\PhpStan\Runtime\V2_2\RuntimeAdapter as V2_2Adapter;

/**
 * Selects the {@see RuntimeAdapter} for the installed PHPStan minor — the seam where a new per-minor adapter
 * slots in. Today there's one, targeting 2.2.x/2.3.x.
 *
 * Overridable so the seam is usable as one: `Analysis\PhpStanEngineFactory` takes this as a dependency, and
 * the fixture harness substitutes a subclass that wraps the adapter to count its passes — which is how "one
 * walk per file, shared by every consumer" is asserted end to end.
 *
 * @internal
 */
class RuntimeAdapterFactory
{
    /**
     * Tested-minor allowlist, widened only as the CI matrix goes green — never open-ended. This ONE
     * constant is the gate, and {@see supportedRange()} renders it as the composer constraint the
     * failure message names, so the check and the message cannot drift apart. The third copy is
     * `phpstan/phpstan` in this package's composer.json, which `RuntimeAdapterFactoryTest` pins to it.
     *
     * @var list<array{int, int}>
     */
    private const SUPPORTED = [[2, 2], [2, 3]];

    public function create(RuntimeConfig $config): RuntimeAdapter
    {
        $version = $this->detectVersion();

        if (in_array($this->majorMinor($version), self::SUPPORTED, true)) {
            return new V2_2Adapter($config);
        }

        throw UnsupportedPhpStanVersionException::forVersion($version, self::supportedRange());
    }

    /** The allowlist as the composer constraint it mirrors — `~2.2.0 || ~2.3.0`. */
    public static function supportedRange(): string
    {
        return implode(' || ', array_map(
            static fn (array $minor): string => sprintf('~%d.%d.0', $minor[0], $minor[1]),
            self::SUPPORTED,
        ));
    }

    private function detectVersion(): string
    {
        return self::installedVersion(InstalledVersions::getAllRawData(), 'phpstan/phpstan') ?? 'unknown';
    }

    /**
     * The version the application installed of a package: the first installed set that installs it, not
     * merely the first that names it. Once PHPStan's phar has loaded, its own Composer metadata joins the
     * process AHEAD of the application's, and there `phpstan/phpstan` is only replaced by the source
     * package, with no version: a read that stopped there would find none, and every engine booted in the
     * process after the first would refuse to start.
     *
     * @param  list<mixed>  $installed  {@see InstalledVersions::getAllRawData()}
     */
    public static function installedVersion(array $installed, string $package): ?string
    {
        foreach ($installed as $set) {
            $version = is_array($set) && is_array($set['versions'] ?? null) && is_array($set['versions'][$package] ?? null)
                ? ($set['versions'][$package]['pretty_version'] ?? null)
                : null;
            if (is_string($version)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function majorMinor(string $version): array
    {
        if (preg_match('/(\d+)\.(\d+)/', $version, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [0, 0];
    }
}
