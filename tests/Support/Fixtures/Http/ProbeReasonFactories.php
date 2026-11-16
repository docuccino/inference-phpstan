<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** Two named constructors passing two reasons that map to one status. */
final class ProbeReasonFactories extends ProbeReasonForwarded
{
    private function __construct(ProbeReason $reason)
    {
        parent::__construct($reason);
    }

    public static function conflict(): self
    {
        return new self(ProbeReason::Conflict);
    }

    public static function stale(): self
    {
        return new self(ProbeReason::Stale);
    }
}
