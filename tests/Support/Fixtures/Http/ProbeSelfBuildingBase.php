<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A base whose factory names `self`, so what it builds is the base however a subclass calls it. */
class ProbeSelfBuildingBase extends HttpException
{
    public static function gone(): self
    {
        return new self(410, 'Gone.');
    }
}
