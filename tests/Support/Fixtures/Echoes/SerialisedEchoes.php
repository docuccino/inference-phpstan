<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use JsonSerializable;
use Symfony\Component\HttpFoundation\Response;

/** The phrase is a property, but the body is whatever `jsonSerialize()` says. */
final class SerialisedEchoes implements JsonSerializable
{
    public readonly string $title;

    public function __construct(Response $rendered)
    {
        $this->title = Response::$statusTexts[$rendered->getStatusCode()] ?? 'Error';
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['message' => $this->title];
    }
}
