<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The static-factory idiom with one factory taking the default and another writing the SAME status. Nothing
 * outside the class can build it, so the two constructions are all of them, and they agree — which is one
 * status the class has, not two.
 */
final class ProbeAgreeingFactories extends HttpException
{
    private function __construct(int $statusCode = 409)
    {
        parent::__construct($statusCode, 'Conflict.');
    }

    public static function moved(): self
    {
        return new self;
    }

    public static function conflicting(): self
    {
        return new self(409);
    }
}
