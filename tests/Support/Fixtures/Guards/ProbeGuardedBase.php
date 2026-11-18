<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Guards;

/** A base whose methods a subclass may call but does not write. Never run. */
abstract class ProbeGuardedBase
{
    protected function inherited(): void {}
}
