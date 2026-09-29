<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

use SensitiveParameter;

/** Tagged from a constructor whose attribute sits on a line of its own, above `function`. */
final class AttributedMessage
{
    public readonly string $channel;

    #[\Deprecated]
    public function __construct(#[SensitiveParameter] public string $text = '')
    {
        $this->channel = 'attributed';
    }
}
