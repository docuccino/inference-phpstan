<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Keeping;

/** A receiver whose method has no body to read. */
interface KeptContract
{
    public function status(int $fallback): int;
}
