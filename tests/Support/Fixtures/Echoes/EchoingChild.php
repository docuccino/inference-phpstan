<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/** Hands its parent the response under another name and position, then reads back what the parent wrote. */
final class EchoingChild extends EchoingParent
{
    public readonly int $code;

    public readonly string $phrase;

    public function __construct(string $detail, Response $response)
    {
        parent::__construct($response);

        $this->code = $this->status;
        $this->phrase = $this->title;
    }
}
