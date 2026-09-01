<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A parameter initialiser that CONSTRUCTS something — legal since PHP 8.1, and a default no reader may ask
 * `ReflectionParameter` for, because reflection answers by running it.
 */
final class ProbeConstructedDefault extends HttpException
{
    private function __construct(
        private readonly ProbeSideEffect $marker = new ProbeSideEffect,
        int $statusCode = 422,
    ) {
        parent::__construct($statusCode, 'Rejected.');
    }

    public static function rejected(): self
    {
        return new self;
    }

    public function marker(): ProbeSideEffect
    {
        return $this->marker;
    }
}
