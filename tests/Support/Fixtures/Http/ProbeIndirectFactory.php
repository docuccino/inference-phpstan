<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A factory that builds through another of its own, one hop further than this read goes. */
final class ProbeIndirectFactory extends HttpException
{
    public static function locked(): self
    {
        return self::withStatus(423);
    }

    private static function withStatus(int $statusCode): self
    {
        return new self($statusCode, 'Locked.');
    }
}
