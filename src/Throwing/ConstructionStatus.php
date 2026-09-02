<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;

/**
 * The status ONE call passes in one argument slot, and the one status a SET of them agrees on — the single
 * rule for a question three readers ask.
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
 * {@see agreedIn()} is the same rule over several constructions at once, which a class asks of every `new`
 * it writes of itself and a factory asks of every one in its body. It is stated here rather than in either,
 * for the reason the pair above already learned: two readers of one question drift.
 *
 * @internal
 */
final class ConstructionStatus
{
    /**
     * The one status a SET of constructions agrees on, which is the only thing a set of them can state.
     * One that folds to nothing takes the whole answer with it, and two that fold to different statuses
     * state neither — a class or a factory that builds two ways genuinely has no one status.
     *
     * Each construction arrives as the SITE it is written at, because a class's constructions need not
     * all be written in one place: a base's `new static(…)` builds the subclass from the base's own file
     * and class, and folding its argument anywhere else reads a scope the line was never in.
     *
     * @param  list<ConstructionSite>  $sites
     * @param  array{names: list<string>, default: int|null}  $constructor  as {@see inSlot()}
     * @param  callable(Node\Expr, ConstructionSite): ?int  $fold  the caller's own constant fold, taking the
     *                                                             argument and the site it sits at
     */
    public static function agreedIn(array $sites, int $slot, array $constructor, callable $fold): ?int
    {
        $status = null;

        foreach ($sites as $site) {
            $one = self::inSlot(
                $site->construction,
                $slot,
                $constructor,
                static fn (Node\Expr $argument): ?int => $fold($argument, $site),
            );

            if ($one === null || ($status !== null && $one !== $status)) {
                return null;
            }

            $status = $one;
        }

        return $status;
    }

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
