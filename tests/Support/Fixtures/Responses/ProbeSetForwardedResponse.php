<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\Response;

/** Sets the status its caller passes, once its parent's constructor has run. */
final class ProbeSetForwardedResponse extends Response
{
    public function __construct(int $code)
    {
        parent::__construct('', 200);

        $this->setStatusCode($code);
    }
}
