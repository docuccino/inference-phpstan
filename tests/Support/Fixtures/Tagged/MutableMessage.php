<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** Not readonly: anyone may write the property after construction. */
final class MutableMessage
{
    public ChannelKind $channel;

    public function __construct()
    {
        $this->channel = ChannelKind::Email;
    }
}
