<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** One reason, written into the parent call, so every instance answers its status. */
final class ProbeReasonPinned extends HttpException
{
    public function __construct()
    {
        parent::__construct(ProbeReason::Gone->status(), 'Gone.');
    }
}
