<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The same defaulted status behind a PUBLIC constructor, which any caller may pass another value to. */
final class ProbePublicDefault extends HttpException
{
    public function __construct(int $statusCode = 422)
    {
        parent::__construct($statusCode, 'Rejected.');
    }
}
