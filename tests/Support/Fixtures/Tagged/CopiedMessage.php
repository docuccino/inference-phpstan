<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** Copies itself without properties, which carries every readonly value over unchanged. */
final readonly class CopiedMessage
{
    public string $channel;

    public function __construct(public string $body)
    {
        $this->channel = 'copy';
    }

    public function again(): static
    {
        return clone $this;
    }
}
