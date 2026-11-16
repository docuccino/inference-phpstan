<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Held;

/** A readonly class, every promoted property of which is readonly whether or not it says so. */
final readonly class HeldReadonlyProblem
{
    public function __construct(public int $status, public string $title) {}
}
