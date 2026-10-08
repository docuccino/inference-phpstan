<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** Runs the inherited constructor with a helper of its own in place of the parent's. */
final class SilencedProbe extends DescribingProbe
{
    protected function describe(): void {}

    // Not an override: the parent's `$this->own()` still runs the parent's.
    private function own(): void {}
}
