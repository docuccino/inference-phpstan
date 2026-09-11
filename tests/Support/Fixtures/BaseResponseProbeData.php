<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

/**
 * Writes the response once for every payload under it. Its file is not the subclass's and it is not
 * spatie's concern's either, which is the whole distinction: the body that runs is this one.
 */
abstract class BaseResponseProbeData extends Data
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(['id' => $this->id ?? null], 202);
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        return ['id' => $this->id ?? null];
    }
}
