<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** Runs a constructor of its own, so the parent's properties are assigned somewhere else. */
final class ReplacingProbe extends AssigningProbe
{
    public string $own;

    public function __construct()
    {
        parent::__construct('p');
        $this->own = 'o';
    }
}
