<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use Composer\InstalledVersions;
use Docuccino\Inference\PhpStan\Runtime\V2_2\RuntimeAdapter as V2_2Adapter;
use OutOfRangeException;

/**
 * Selects the {@see RuntimeAdapter} for the installed PHPStan minor — the seam where a new per-minor adapter
 * slots in. Today there's one, targeting 2.2.x/2.3.x.
 *
 * @internal
 */
final class RuntimeAdapterFactory
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
        try {
            $version = InstalledVersions::getPrettyVersion('phpstan/phpstan');
        } catch (OutOfRangeException) {
            $version = null;
        }

        return $version ?? 'unknown';
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
