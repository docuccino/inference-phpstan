<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** A subclass handing the base a read of its own argument: a read of a read, which forwards no slot. */
final class ProbeReasonReread extends ProbeReasonForwarded
{
    public function __construct(ProbeReason $reason)
    {
        parent::__construct($reason->escalated());
    }
}
