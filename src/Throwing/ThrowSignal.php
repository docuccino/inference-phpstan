<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ThrowDisposition;

/**
 * Whether a throw the analysis surfaced is an API error the document should carry, or vendor plumbing.
 *
 * Only a DECLARED throw from outside the APPLICATION can be plumbing — a literal `throw` and a `@throws`
 * the application wrote are both it saying what it raises, wherever in its own source that is — a modular
 * PSR-4 root included, which the build primes and never descends into. What demotes the rest is that the
 * status FELL BACK, never the number it fell back to: a class pinning 500 is stating an API fact, and a
 * status nothing could read is no evidence of plumbing either.
 *
 * @internal
 */
final class ThrowSignal
{
    public static function disposition(bool $isLiteral, bool $calleeIsApplication, bool $fellBack): ThrowDisposition
    {
        return $isLiteral || $calleeIsApplication || ! $fellBack
            ? ThrowDisposition::Signal
            : ThrowDisposition::Internal;
    }
}
