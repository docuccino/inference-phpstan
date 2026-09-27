<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** Not final: a subclass could construct its own value, so nothing is fixed. */
readonly class OpenMessage
{
    public ChannelKind $channel;

    public function __construct()
    {
        $this->channel = ChannelKind::Email;
    }
}
