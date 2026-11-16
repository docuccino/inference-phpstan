<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Refused for a reason: the status is whatever the case passed here maps to. */
class ProbeReasonForwarded extends HttpException
{
    public function __construct(ProbeReason $reason, string $message = 'Refused.')
    {
        parent::__construct($reason->status(), $message);
    }
}
