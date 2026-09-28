<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;

/** Takes `toResponse()` from an application trait; {@see WritesProbeResourceResponse} wrote it. */
final class TraitResponseProbeResource extends JsonResource
{
    use WritesProbeResourceResponse;
}
