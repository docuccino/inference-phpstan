<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Held;

/** A problem promoting some of its properties readonly, one writable, and one written in its body. */
class HeldProblem
{
    public readonly string $type;

    public function __construct(
        public readonly int $status,
        public string $title,
        string $type,
        protected readonly string $detail = '',
    ) {
        $this->type = $type;
    }
}
