<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Constructed;

/** A trait's method, which PHP reports as the using class's though only the trait writes it. */
trait BuildsFromTrait
{
    public function fromTrait(): object
    {
        return new \SplStack;
    }
}
