<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\JsonResponse;

/** A problem document: the caller's body and status, under the media type the class fixes. */
class ProbeProblemResponse extends JsonResponse
{
    /** @param  array<string, mixed>  $problem */
    public function __construct(array $problem, int $status)
    {
        parent::__construct($problem, $status, ['Content-Type' => 'application/problem+json']);
    }
}
