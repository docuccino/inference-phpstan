<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/** The status promoted from the constructor, and its phrase read through it with a constant to fall back on. */
final class PromotedEchoes
{
    private const FALLBACK = 'Unknown status';

    public readonly string $title;

    public function __construct(public readonly int $status)
    {
        $this->title = Response::$statusTexts[$this->status] ?? self::FALLBACK;
    }
}
