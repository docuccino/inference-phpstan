<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A class that pins its status and still has a factory, whose argument the constructor never forwards. */
final class ProbePinsWithFactory extends HttpException
{
    private function __construct(int $sequence = 0)
    {
        parent::__construct(410, 'Gone, attempt '.$sequence.'.');
    }

    public static function gone(): self
    {
        return new self(409);
    }
}
