<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

/** Says nothing about its response; {@see BaseResponseProbeData} wrote it. */
final class InheritedResponseProbeData extends BaseResponseProbeData
{
    public function __construct(
        public string $id,
    ) {}
}
