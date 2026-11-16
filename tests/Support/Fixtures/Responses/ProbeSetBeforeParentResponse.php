<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\Response;

/** Sets a status the parent's constructor then replaces with the one it is handed. */
final class ProbeSetBeforeParentResponse extends Response
{
    public function __construct()
    {
        $this->setStatusCode(410);

        parent::__construct('', 202);
    }
}
