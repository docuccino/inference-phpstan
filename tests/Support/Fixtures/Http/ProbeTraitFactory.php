<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The factory idiom with the factories in a trait — written in another file, so a `new self(…)` there is
 * one the read never sees and the default stops being provable.
 */
final class ProbeTraitFactory extends HttpException
{
    use ProbeMakesItself;

    private function __construct(int $statusCode = 422)
    {
        parent::__construct($statusCode, 'Rejected.');
    }
}
