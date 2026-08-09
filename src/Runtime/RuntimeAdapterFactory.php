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
    /** Tested-minor allowlist, widened only as the CI matrix goes green — never open-ended. */
    private const SUPPORTED = '~2.2.0 || ~2.3.0';

    public function create(RuntimeConfig $config): RuntimeAdapter
    {
        $version = $this->detectVersion();
        [$major, $minor] = $this->majorMinor($version);

        if ($major === 2 && ($minor === 2 || $minor === 3)) {
            return new V2_2Adapter($config);
        }

        throw UnsupportedPhpStanVersionException::forVersion($version, self::SUPPORTED);
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
