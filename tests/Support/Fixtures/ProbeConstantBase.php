<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

/** The base a `parent::` reaches, and the file a constant declared here is edited in. */
class ProbeConstantBase implements ProbeConstantContract
{
    public const FROM_BASE = 410;

    /** Redeclared by the subclass, the only shape `parent::` and `self::` answer differently for. */
    public const SHADOWED = 410;
}
