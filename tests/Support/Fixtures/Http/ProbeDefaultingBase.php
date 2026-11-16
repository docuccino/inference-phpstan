<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A base forwarding a defaulted status that a subclass may leave empty. */
class ProbeDefaultingBase extends HttpException
{
    public function __construct(string $message = '', int $statusCode = 409)
    {
        parent::__construct($statusCode, $message);
    }
}
