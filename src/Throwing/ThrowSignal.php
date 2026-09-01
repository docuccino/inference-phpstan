<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ThrowDisposition;

/**
 * Whether a throw the analysis surfaced is an API error the document should carry, or vendor plumbing.
 *
 * Only a DECLARED throw from outside the project can be plumbing — a literal `throw` and a project callee's
 * `@throws` are both the application saying what it raises. What demotes the rest is that the status FELL
 * BACK, never the number it fell back to: a class pinning 500 is stating an API fact, and a status nothing
 * could read is no evidence of plumbing either.
 *
 * @internal
 */
final class ThrowSignal
{
    public static function disposition(bool $isLiteral, bool $calleeIsProject, bool $fellBack): ThrowDisposition
    {
        return $isLiteral || $calleeIsProject || ! $fellBack
            ? ThrowDisposition::Signal
            : ThrowDisposition::Internal;
    }
}
