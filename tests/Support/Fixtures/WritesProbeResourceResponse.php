<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writes a resource's response once for every resource using it. Reflection names the using class as the
 * declarer and this file as where the body is, so only the method's own file says whose body runs.
 */
trait WritesProbeResourceResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(['queued' => true], 202);
    }
}
