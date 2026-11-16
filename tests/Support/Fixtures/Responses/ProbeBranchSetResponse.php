<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\Response;

/** Sets its status on one branch only, so no one status is the class's. */
final class ProbeBranchSetResponse extends Response
{
    public function __construct(string $content = '')
    {
        parent::__construct($content, 200);

        if ($content === '') {
            $this->setStatusCode(204);
        }
    }
}
