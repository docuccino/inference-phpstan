<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\JsonResponse;

/** Writes the body parameter before handing it on, so the caller's argument is not what is sent. */
final class ProbeNormalisedResponse extends JsonResponse
{
    /** @param  array<string, mixed>  $data */
    public function __construct(array $data)
    {
        $data = ['data' => $data];

        parent::__construct($data, 200);
    }
}
