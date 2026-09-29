<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** A parent whose methods run on a subclass's `$this` without the analyser following them. */
class EscapeParent
{
    public function __construct() {}

    public function inherited(): void {}
}
