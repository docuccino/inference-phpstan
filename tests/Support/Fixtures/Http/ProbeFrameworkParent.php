<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** A framework fixed-status exception with a constructor of its own, which only chooses the message. */
final class ProbeFrameworkParent extends UnprocessableEntityHttpException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Refused: '.$reason);
    }
}
