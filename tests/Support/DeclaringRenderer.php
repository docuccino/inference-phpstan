<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Attributes\ErrorComponent;

/** Reflection input for the `#[ErrorComponent]` reader: the shapes a render method can be written in. */
class DeclaringRenderer
{
    #[ErrorComponent('PortalRejection')]
    public function positional(): void {}

    #[ErrorComponent(name: 'PortalThrottle')]
    public function named(): void {}

    #[ErrorComponent('Not Found!')]
    public function illegal(): void {}

    public function undeclared(): void {}
}
