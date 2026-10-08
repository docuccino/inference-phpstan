<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** Declares a property of its own while running the constructor it inherits. */
final class InheritingProbe extends DescribingProbe
{
    public string $own;
}
