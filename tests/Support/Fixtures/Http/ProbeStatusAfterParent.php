<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The same parameter written AFTER it was forwarded. Every instance really is the 422 the default names,
 * and a read that folds in the body's END scope answers 503 — a value the parent never received. Refusing
 * both is the safe direction: position is not read, so a write anywhere retires the default.
 */
final class ProbeStatusAfterParent extends HttpException
{
    public int $reported = 0;

    private function __construct(int $statusCode = 422)
    {
        parent::__construct($statusCode, 'Rejected.');

        $statusCode = 503;
        $this->reported = $statusCode;
    }

    public static function rejected(): self
    {
        return new self;
    }
}
