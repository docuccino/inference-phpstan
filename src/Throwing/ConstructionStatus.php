<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;

/**
 * The status ONE call passes in one argument slot — the single rule for a question two readers ask.
 *
 * A `throw new X(…)` and the `new self(…)` inside the factory a `throw X::y()` names are the same
 * construction one hop apart, so they owe the same answer; they were two readers with two rules, and the
 * pair disagreed twice over — one applied the constructor's default for an absent slot and the other read
 * the slot as unstated, and one placed a named argument by the callee's signature while the other only
 * counted positions. Everything that differs between them is now the fold alone, which is genuinely
 * different: one has a `Scope` at the call, the other reaches the analyser through {@see ClassBodies}.
 *
 * Passing NO parameter names and NO default is how a caller says the callee's signature is not in play —
 * the `abort()`-style registry entry, whose call PHPStan has already normalised.
 *
 * @internal
 */
final class ConstructionStatus
{
    /**
     * @param  array{names: list<string>, default: int|null}  $constructor  the callee's parameters in
     *                                                                      declaration order, and the
     *                                                                      constant default of `$slot`
     * @param  callable(Node\Expr): ?int  $fold  the caller's own constant fold
     */
    public static function inSlot(Node\Expr\CallLike $call, int $slot, array $constructor, callable $fold): ?int
    {
        // A first-class callable holds a placeholder where its arguments go, and `getArgs()` only ASSERTS
        // that — with `zend.assertions=-1` the placeholder would reach the fold below as an expression.
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $slots = ArgumentSlots::of($call->getArgs(), $constructor['names']);

        // "Absent" and "past an unreadable spread" both read as no argument, and only the first of them
        // means the parameter's default is what was passed.
        if (! $slots->knows($slot)) {
            return null;
        }

        $argument = $slots->at($slot);

        return HttpStatusCode::folded($argument === null ? $constructor['default'] : $fold($argument));
    }
}
