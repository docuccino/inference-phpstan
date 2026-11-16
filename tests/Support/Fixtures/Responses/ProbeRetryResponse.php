<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\JsonResponse;

/** A status and a body the caller passes, the status defaulting to 503. */
class ProbeRetryResponse extends JsonResponse
{
    /** @param  array<string, mixed>  $data */
    public function __construct(array $data, int $status = 503)
    {
        parent::__construct($data, $status);
    }
}
