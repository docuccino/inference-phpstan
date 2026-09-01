<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A factory replaying arguments held elsewhere: the status may well be in there, unreadably. */
final class ProbeSpreadFactory extends HttpException
{
    /**
     * @param  list<mixed>  $arguments
     */
    public static function replaying(array $arguments): self
    {
        return new self(...$arguments);
    }
}
