<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ThrowDisposition;

/**
 * Whether a throw the analysis surfaced is an API error the document should carry, an assertion, or vendor
 * plumbing.
 *
 * Only a DECLARED throw from outside the APPLICATION can be plumbing — a literal `throw` and a `@throws`
 * the application wrote are both it saying what it raises, wherever in its own source that is — a modular
 * PSR-4 root included, which the build primes and never descends into. What demotes the rest is that the
 * status FELL BACK, never the number it fell back to: a class pinning 500 is stating an API fact, and a
 * status nothing could read is no evidence of plumbing either.
 *
 * What the application raises with no status, where only its own guarantees failing reaches it, is an
 * assertion rather than an error ({@see Assertions}): which of the two it is never depends on how the throw
 * is written, because `$asserts` is read off the class and the path, never off the form.
 *
 * @internal
 */
final class ThrowSignal
{
    public static function disposition(bool $isLiteral, bool $calleeIsApplication, bool $fellBack, bool $asserts): ThrowDisposition
    {
        if (! $isLiteral && ! $calleeIsApplication && $fellBack) {
            return ThrowDisposition::Internal;
        }

        return $fellBack && $asserts ? ThrowDisposition::Assertion : ThrowDisposition::Signal;
    }
}
