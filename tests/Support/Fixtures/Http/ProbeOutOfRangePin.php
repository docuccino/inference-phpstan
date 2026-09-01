<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A constant reaching the parent that is no HTTP status at all, and would become the response key `"0"`. */
final class ProbeOutOfRangePin extends HttpException
{
    public function __construct()
    {
        parent::__construct(0, 'Rejected.');
    }
}
