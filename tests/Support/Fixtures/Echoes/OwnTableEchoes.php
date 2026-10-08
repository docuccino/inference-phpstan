<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/** A phrase from a table that only shares the name. */
final class OwnTableEchoes
{
    public readonly string $title;

    public function __construct(Response $rendered)
    {
        $this->title = OwnTableResponse::$statusTexts[$rendered->getStatusCode()] ?? 'Error';
    }
}
