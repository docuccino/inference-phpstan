<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** The out-of-file construction {@see ProbeTraitFactory} cannot be read without. */
trait ProbeMakesItself
{
    public static function conflicting(): static
    {
        return new static(409);
    }
}
