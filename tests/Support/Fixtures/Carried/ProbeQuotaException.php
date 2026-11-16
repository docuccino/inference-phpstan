<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried;

/** Two levels below the framework, building the response it relays. */
final class ProbeQuotaException extends ProbeRelayedException
{
    public function __construct()
    {
        parent::__construct(response()->json(['error' => 'quota'], 429), 'quota');
    }
}
