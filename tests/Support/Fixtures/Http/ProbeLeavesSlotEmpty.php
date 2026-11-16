<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** A subclass that hands its base no status, so every instance carries the base's default. */
final class ProbeLeavesSlotEmpty extends ProbeDefaultingBase
{
    public function __construct()
    {
        parent::__construct('Taken.');
    }
}
