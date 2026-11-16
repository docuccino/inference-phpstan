<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\Response;

/** Sets its status once its parent's constructor has set the default one. */
final class ProbeSetStatusResponse extends Response
{
    public function __construct(string $content = '')
    {
        parent::__construct($content);

        $this->setStatusCode(410);
    }
}
