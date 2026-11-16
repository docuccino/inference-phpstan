<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\JsonResponse;

/** Hands the framework no status at all, so it is sent with the framework's own default. */
final class ProbeDataResponse extends JsonResponse
{
    /** @param  array<string, mixed>  $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
    }
}
