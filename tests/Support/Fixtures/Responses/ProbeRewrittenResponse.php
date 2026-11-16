<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\JsonResponse;

/** Replaces the body it handed its parent, so what it handed is not what is sent. */
final class ProbeRewrittenResponse extends JsonResponse
{
    public function __construct()
    {
        parent::__construct(['draft' => true], 202);

        $this->setData(['final' => true]);
    }
}
