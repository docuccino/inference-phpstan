<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Spatie\LaravelData\Data;

/** Takes both methods from an application trait; {@see WritesProbeResponse} wrote them. */
final class TraitResponseProbeData extends Data
{
    use WritesProbeResponse;

    public function __construct(
        public string $id,
    ) {}
}
