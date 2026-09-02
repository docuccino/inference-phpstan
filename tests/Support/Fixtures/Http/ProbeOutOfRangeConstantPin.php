<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\ProbeConstantHolder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The pin {@see ProbeOutOfRangePin} refuses, written as the constant an application really reaches for —
 * so the fold refuses a value read out of ANOTHER file, and that file decided the answer all the same.
 */
final class ProbeOutOfRangeConstantPin extends HttpException
{
    public function __construct()
    {
        parent::__construct(ProbeConstantHolder::OUT_OF_RANGE, 'Rejected.');
    }
}
