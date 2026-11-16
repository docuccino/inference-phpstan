<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried;

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/** Takes the response it hands on second. */
final class ProbeReorderedException extends HttpResponseException
{
    public function __construct(string $reason, Response $response)
    {
        parent::__construct($response);
    }
}
