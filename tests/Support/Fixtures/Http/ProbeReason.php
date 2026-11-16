<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** A refusal reason that maps each case to the status it answers with — two of them to the same one. */
enum ProbeReason: string
{
    case Gone = 'gone';
    case Conflict = 'conflict';
    case Stale = 'stale';

    public function status(): int
    {
        return match ($this) {
            self::Gone => 410,
            self::Conflict, self::Stale => 409,
        };
    }

    public function escalated(): self
    {
        return self::Gone;
    }
}
