<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

/**
 * A pure (non-backed) enum — `->value` has nothing to fold. Autoloaded so it is visible across Paratest
 * processes.
 */
enum PlainProbeEnum
{
    case One;
    case Two;
}
