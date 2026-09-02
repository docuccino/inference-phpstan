<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

/** A constant declared on an INTERFACE, which no walk of a class hierarchy's own files would name. */
interface ProbeConstantContract
{
    public const FROM_INTERFACE = 451;
}
