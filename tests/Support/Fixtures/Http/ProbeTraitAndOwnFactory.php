<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A PRIVATE constructor beside a trait, which is where "only this class can build it" stops being true: a
 * trait's methods are the class's own, so the `new static(409)` in one reaches the private constructor from
 * a file this read never opens. What is in front of it — a factory of its own at 423 — agrees with itself,
 * and publishing that would state half the class's statuses as all of them.
 */
final class ProbeTraitAndOwnFactory extends HttpException
{
    use ProbeMakesItself;

    private function __construct(int $statusCode = 423)
    {
        parent::__construct($statusCode, 'Locked.');
    }

    public static function locked(): self
    {
        return new self(423);
    }
}
