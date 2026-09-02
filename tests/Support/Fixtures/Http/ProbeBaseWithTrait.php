<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A base that takes one factory from a trait and declares another itself, so what builds it — and every
 * subclass — is written in two files and only one of them is this read's. What is in front of it agrees
 * with itself at 410, and publishing that would state half the statuses as all of them.
 */
abstract class ProbeBaseWithTrait extends HttpException
{
    use ProbeMakesItself;

    public static function gone(): static
    {
        return new static(410, 'Gone.');
    }
}
