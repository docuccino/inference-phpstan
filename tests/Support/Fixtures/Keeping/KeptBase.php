<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Keeping;

/** What {@see Kept} inherits and calls through `parent::`. */
class KeptBase
{
    public int $status = 200;

    public function reset(int $code): void
    {
        $this->status = $code;
    }
}
