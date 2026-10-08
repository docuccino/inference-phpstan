<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** A constructor's helpers, one a subclass may override and one it cannot. */
class DescribingProbe
{
    public string $title;

    public function __construct()
    {
        $this->describe();
    }

    protected function describe(): void
    {
        $this->title = 'x';
    }

    private function own(): void
    {
        $this->title = 'x';
    }
}
