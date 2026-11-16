<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

/** Leaves its parent's defaulted status empty, so it is sent with the parent's 503, and fixes the body. */
final class ProbeUnavailableResponse extends ProbeRetryResponse
{
    public function __construct()
    {
        parent::__construct(['retry' => false]);
    }
}
