<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** No constructor at all: whoever builds it assigns its properties. */
final class UnconstructedProbe
{
    public string $title;
}
