<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A base that pins for everything below it, whatever message a subclass chooses. */
abstract class ProbePinningBase extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(410, $message);
    }
}
