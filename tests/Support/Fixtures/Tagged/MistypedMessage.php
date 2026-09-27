<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** A value the declared type does not hold as written: strict types refuse it, loose ones coerce it. */
final readonly class MistypedMessage
{
    public int $priority;

    public string $code;

    public function __construct()
    {
        $this->priority = '5';
        $this->code = 7;
    }
}
