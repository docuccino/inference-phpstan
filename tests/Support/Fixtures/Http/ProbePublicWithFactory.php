<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A PUBLIC constructor whose one in-class construction takes its default. The class states nothing for
 * every instance — any caller may pass another status, and a `throw new ProbePublicWithFactory(423)` really
 * is a 423 — while the 409 its own factory builds is still the best thing left where a throw carries no
 * construction to read.
 */
final class ProbePublicWithFactory extends HttpException
{
    public function __construct(int $statusCode = 409)
    {
        parent::__construct($statusCode, 'Conflict.');
    }

    public static function conflicting(): self
    {
        return new self;
    }
}
