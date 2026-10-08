<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** The phrase read straight off the response, through a subclass that inherits the table, with no fallback. */
final class InlineEchoedProblem
{
    public readonly string $title;

    public function __construct(Response $rendered, public readonly string $detail = '')
    {
        $this->title = JsonResponse::$statusTexts[$rendered->getStatusCode()];
    }
}
