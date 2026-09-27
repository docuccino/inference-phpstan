<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** Tagged through a class constant that holds an enum case, and an int literal beside it. */
final readonly class PushMessage
{
    public const CHANNEL = ChannelKind::Push;

    public ChannelKind $channel;

    public int $version;

    public function __construct(public string $title)
    {
        $this->channel = self::CHANNEL;
        $this->version = 2;
    }
}
