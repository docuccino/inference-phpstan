<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/** A problem base whose constructor its subclasses run through `parent::__construct()`. */
class EchoingParent
{
    public readonly int $status;

    public readonly string $title;

    public function __construct(Response $rendered)
    {
        $this->status = $rendered->getStatusCode();
        $this->title = Response::$statusTexts[$this->status] ?? 'Error';
    }
}
