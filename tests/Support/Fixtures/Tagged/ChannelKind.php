<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

enum ChannelKind: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
}
