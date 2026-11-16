<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\JsonResponse;

/** Replaces a header it handed its parent through the bag the headers live in. */
final class ProbeHeaderBagResponse extends JsonResponse
{
    public function __construct()
    {
        parent::__construct([], 200, ['Content-Type' => 'application/vnd.draft+json']);

        $this->headers->set('Content-Type', 'application/vnd.final+json');
    }
}
