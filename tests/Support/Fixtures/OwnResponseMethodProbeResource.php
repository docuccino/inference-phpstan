<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Writes `response()` and leaves `toResponse()` to the framework: only the first is a helper of its own. */
final class OwnResponseMethodProbeResource extends JsonResource
{
    /**
     * @param  Request|null  $request
     */
    public function response($request = null): JsonResponse
    {
        return new JsonResponse(['queued' => true], 202);
    }
}
