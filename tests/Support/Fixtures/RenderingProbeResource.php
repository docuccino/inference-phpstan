<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A resource the framework renders: `toResponse()` and `response()` are both Laravel's own. */
final class RenderingProbeResource extends JsonResource
{
    /**
     * @return array{id: int}
     */
    public function toArray(Request $request): array
    {
        return ['id' => 1];
    }
}
