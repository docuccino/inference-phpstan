<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried;

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/** Replaces the response it handed its parent once the parent's constructor has run. */
final class ProbeReplacedException extends HttpResponseException
{
    public function __construct(Response $response)
    {
        parent::__construct($response);

        $this->response = response()->json(['error' => 'replaced'], 409);
    }
}
