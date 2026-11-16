<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried;

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/** Rewrites the response parameter before handing it on. */
final class ProbeWrappedException extends HttpResponseException
{
    public function __construct(Response $response)
    {
        $response = $response->setStatusCode(409);

        parent::__construct($response);
    }
}
