<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** Counts its own construction, so "nothing was executed" is an assertion rather than a hope. */
final class ProbeSideEffect
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }
}
