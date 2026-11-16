<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** A failure whose backing value IS its status. */
enum ProbeStatusValue: int
{
    case Gone = 410;
    case Locked = 423;
}
