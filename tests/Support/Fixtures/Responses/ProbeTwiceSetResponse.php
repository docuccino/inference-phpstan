<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\Response;

/** Sets its status twice; this read places neither as the one sent. */
final class ProbeTwiceSetResponse extends Response
{
    public function __construct()
    {
        parent::__construct('');

        $this->setStatusCode(202);
        $this->setStatusCode(410);
    }
}
