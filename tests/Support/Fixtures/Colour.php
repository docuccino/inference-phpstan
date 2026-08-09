<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Docuccino\Inference\PhpStan\Translation\TypeTranslator;

/**
 * A tiny backed enum used to exercise {@see TypeTranslator}
 * enum handling without booting a container.
 */
enum Colour: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
}
