<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** Tagged from a string literal on one readonly property, after other work in the constructor. */
final class SmsMessage
{
    public readonly string $channel;

    public string $text;

    public function __construct(string $text, public int $segments = 1)
    {
        $this->text = trim($text);
        $this->channel = 'sms';
    }
}
