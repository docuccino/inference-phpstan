<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/** A base whose constructor its subclasses inherit, reading the table through `self`'s own parent. */
class EchoingBase extends Response
{
    public readonly string $title;

    public function __construct(Response $rendered)
    {
        parent::__construct();
        $this->title = self::$statusTexts[$rendered->getStatusCode()] ?? 'Error';
    }
}
