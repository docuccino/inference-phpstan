<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

/**
 * Writes the response once for every payload that uses it. Reflection reports a trait-supplied body
 * against THIS file while naming the using class as its declarer — which is exactly what spatie's own
 * concern looks like, and is why "declared in another file" cannot be the question.
 */
trait WritesProbeResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(['id' => 1], 202);
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        return ['id' => 1];
    }
}
