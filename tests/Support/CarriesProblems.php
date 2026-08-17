<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

/** Passes the trait above along, and reflection reports its method as this trait's too. */
trait CarriesProblems
{
    use DeclaresProblems;
}
