<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** One factory building the class two ways, which states neither status. */
final class ProbeBranchingFactory extends HttpException
{
    public static function forRetry(bool $retryable): self
    {
        return $retryable
            ? new self(423, 'Locked for now.')
            : new self(409, 'Already moved on.');
    }
}
