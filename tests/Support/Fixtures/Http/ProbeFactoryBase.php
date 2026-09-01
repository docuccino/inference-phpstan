<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A base carrying the factory, so a subclass names one it does not declare. */
abstract class ProbeFactoryBase extends HttpException
{
    public static function unavailable(): static
    {
        return new static(503, 'Offline.');
    }
}
