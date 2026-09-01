<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The same idiom defaulting to a number that is no HTTP status, so the default states nothing either. */
final class ProbeOutOfRangeDefault extends HttpException
{
    private function __construct(int $statusCode = 0)
    {
        parent::__construct($statusCode, 'Rejected.');
    }

    public static function rejected(): self
    {
        return new self;
    }
}
