<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use RuntimeException;

/**
 * Thrown by {@see RuntimeAdapterFactory} when the installed PHPStan is outside the tested-minor allowlist.
 *
 * @internal
 */
final class UnsupportedPhpStanVersionException extends RuntimeException
{
    public static function forVersion(string $version, string $supported): self
    {
        return new self(sprintf(
            'Unsupported PHPStan version "%s". Docuccino supports: %s. '
            .'Widen the adapter allowlist only once CI is green on the new minor.',
            $version,
            $supported,
        ));
    }
}
