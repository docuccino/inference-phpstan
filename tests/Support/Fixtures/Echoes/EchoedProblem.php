<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/** RFC 9457's `about:blank` problem built from a rendered response: the status, and its reason phrase through it. */
final class EchoedProblem
{
    public readonly string $type;

    public readonly string $title;

    public readonly int $status;

    public function __construct(Response $rendered)
    {
        $this->type = 'about:blank';
        $this->status = $rendered->getStatusCode();
        $this->title = Response::$statusTexts[$this->status] ?? 'Error';
    }
}
