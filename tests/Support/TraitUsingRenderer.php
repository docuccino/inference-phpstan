<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

/** A renderer whose declaring method arrives through two traits, declaring nothing of its own. */
final class TraitUsingRenderer
{
    use CarriesProblems;
}
