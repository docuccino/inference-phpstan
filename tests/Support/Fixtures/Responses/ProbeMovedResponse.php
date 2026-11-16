<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/** A redirect fixed at a constant, below a root whose constructor it inherits from Symfony. */
final class ProbeMovedResponse extends RedirectResponse
{
    public function __construct(string $url)
    {
        parent::__construct($url, Response::HTTP_MOVED_PERMANENTLY);
    }
}
