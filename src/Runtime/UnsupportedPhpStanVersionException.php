<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use RuntimeException;

/**
 * Thrown by {@see RuntimeAdapterFactory} when the installed PHPStan version is
 * outside the tested-minor allowlist. The allowlist is widened only as the CI
 * matrix goes green — never open-ended (design §1).
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
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
