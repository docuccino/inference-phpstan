<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The factory idiom with one factory that rejects with a 409, so the default speaks for some instances. */
final class ProbeOverridingFactory extends HttpException
{
    private function __construct(int $statusCode = 422)
    {
        parent::__construct($statusCode, 'Rejected.');
    }

    public static function rejected(): self
    {
        return new self;
    }

    public static function conflicting(): self
    {
        return new self(409);
    }
}
