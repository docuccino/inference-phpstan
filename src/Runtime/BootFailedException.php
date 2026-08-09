<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use RuntimeException;

/**
 * Thrown when the PHPStan container or Larastan bootstrap fails to come up. The
 * engine factory catches this and falls back to a `NullTypeEngine` so
 * docblock/attribute-only docs still build (design §3).
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class BootFailedException extends RuntimeException {}
