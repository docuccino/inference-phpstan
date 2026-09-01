<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A factory naming its arguments rather than counting them, which a position-only reader would miss. */
final class ProbeNamedFactory extends HttpException
{
    public static function conflicting(): self
    {
        return new self(message: 'The record moved on.', statusCode: 409);
    }
}
