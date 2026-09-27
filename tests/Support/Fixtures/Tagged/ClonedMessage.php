<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** A __clone may re-initialise a readonly property, so a copy need not hold the constructor's value. */
final readonly class ClonedMessage
{
    public string $channel;

    public function __construct()
    {
        $this->channel = 'email';
    }

    public function __clone() {}
}
