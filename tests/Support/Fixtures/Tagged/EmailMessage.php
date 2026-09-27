<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** Tagged from a backed enum case in a readonly class's constructor. */
final readonly class EmailMessage
{
    public ChannelKind $channel;

    public function __construct(public string $subject, public string $body)
    {
        $this->channel = ChannelKind::Email;
    }
}
