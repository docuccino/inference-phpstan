<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use RuntimeException;

/**
 * The PHPStan container or Larastan bootstrap failed to come up. The engine factory catches this and falls
 * back to a `NullTypeEngine`, so docblock/attribute-only docs still build.
 *
 * @internal
 */
final class BootFailedException extends RuntimeException {}
