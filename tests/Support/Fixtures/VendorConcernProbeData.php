<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Spatie\LaravelData\Data;

/** Takes `toResponse()` and `transform()` straight from spatie's concerns — the modelled shape. */
final class VendorConcernProbeData extends Data
{
    public function __construct(
        public string $id,
    ) {}
}
