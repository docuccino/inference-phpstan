<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried;

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/** Hands its parent the response it is given. */
class ProbeRelayedException extends HttpResponseException
{
    public function __construct(Response $response, public readonly string $upstream = 'billing')
    {
        parent::__construct($response);
    }
}
