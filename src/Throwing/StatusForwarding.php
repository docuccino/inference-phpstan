<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\LocalWrites;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * The statements half of {@see HttpExceptionStatus}: what a constructor hands its parent in one argument
 * slot, whether the constructor moves that value before handing it over, and whether the class ever builds
 * itself writing that same slot. Nothing here resolves a type, so it answers off the parse alone.
 *
 * @internal
 */
final class StatusForwarding
{
    /**
     * The one `parent::__construct()` a body makes, or null where it makes none or several. Two of them is
     * a constructor choosing its status by branch, which is not one status the class states.
     *
     * @param  array<array-key, Node\Stmt>  $statements
     */
    public static function parentCall(array $statements): ?Node\Expr\StaticCall
    {
        /** @var list<Node\Expr\StaticCall> $calls */
        $calls = (new NodeFinder)->find(
            $statements,
            static fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && $node->class->toLowerString() === 'parent'
                && $node->name instanceof Node\Identifier
                && $node->name->toLowerString() === '__construct'
                // A first-class callable holds a placeholder where its arguments go; it calls nothing.
                && ! $node->isFirstClassCallable(),
        );

        return count($calls) === 1 ? $calls[0] : null;
    }

    /**
     * The expression a `parent::__construct()` puts in one slot of the parent's signature. The parameter
     * names are passed so an argument written by NAME is found in the position it fills rather than missed
     * by a reader that only counts.
     *
     * Typed to the one call {@see parentCall()} answers with, whose finder has already refused a
     * first-class callable — a wider parameter here would only invite a caller the placeholder guard is
     * missing from.
     *
     * @param  list<string>  $paramNames  the callee's parameters in declaration order
     */
    public static function argumentAt(Node\Expr\StaticCall $call, int $slot, array $paramNames): ?Node\Expr
    {
        return ArgumentSlots::of($call->getArgs(), $paramNames)->at($slot);
    }

    /**
     * Whether a body writes the local `$variable` anywhere in it — the guard on reading a parameter's
     * DEFAULT as the value its `parent::__construct()` received. A constructor that normalises its status
     * before forwarding it (`if ($errors === []) { $statusCode = 400; }`) hands the parent something the
     * default does not name, and publishing the default there is a precise false status rather than a
     * vague one.
     *
     * Position is deliberately not read: a write AFTER the forwarding cannot have changed what was
     * forwarded, so refusing on one costs a pin the class really did state — the safe direction, and it
     * keeps this reading the same whole-body grammar {@see LocalWrites} states once for the engine. The one
     * write that grammar cannot see is a callee assigning through a by-reference parameter, which needs
     * reflection on that callee; no application in the corpus writes an exception's status that way, and a
     * syntactic over-approximation of it would refuse ordinary constructors that merely pass their status
     * to a helper.
     *
     * @param  array<array-key, Node\Stmt>  $statements
     */
    public static function reassigns(array $statements, string $variable): bool
    {
        return (new NodeFinder)->findFirst(
            $statements,
            static function (Node $node) use ($variable): bool {
                $assignment = LocalWrites::assignment($node);

                return ($assignment !== null && $assignment[0] === $variable)
                    || in_array($variable, LocalWrites::retires($node), true)
                    // A write naming no single local (`$$name = …`, `extract()`) may have landed on this one.
                    || LocalWrites::retiresEveryLocal($node);
            },
        ) !== null;
    }

    /**
     * Whether anything in `$statements` builds `$class` itself and writes `$slot` — the factory that
     * rejects with a 409 where its siblings all take the default. One of those and the default speaks for
     * some of the class's instances and not all, which is not a status the class pins.
     *
     * A slot nothing can be said about counts as written, on both the forms that hide one: a first-class
     * callable, whose arguments are supplied somewhere this build cannot read, and the tail past an
     * unreadable spread ({@see ArgumentSlots::knows()}), where the argument may well be there and reading
     * its absence as "the default was taken" would publish a status the call never passed.
     *
     * @param  array<array-key, Node\Stmt>  $statements
     * @param  list<string>  $paramNames  the constructor's parameters in declaration order
     */
    public static function writesSlot(array $statements, string $class, int $slot, array $paramNames): bool
    {
        foreach (self::constructionsOf($statements, $class) as $construction) {
            if ($construction->isFirstClassCallable()) {
                return true;
            }

            $slots = ArgumentSlots::of($construction->getArgs(), $paramNames);
            if (! $slots->knows($slot) || $slots->at($slot) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `new` in a body that builds `$class` — a factory's own, and one nested in a closure it carries.
     *
     * @param  array<array-key, Node\Stmt>  $statements
     * @return list<Node\Expr\New_>
     */
    public static function constructionsOf(array $statements, string $class): array
    {
        /** @var list<Node\Expr\New_> $constructions */
        $constructions = (new NodeFinder)->find(
            $statements,
            static fn (Node $node): bool => $node instanceof Node\Expr\New_ && self::constructs($node, $class),
        );

        return $constructions;
    }

    /** Whether a `new` builds `$class` — by name, or through the `self`/`static` that mean it here. */
    private static function constructs(Node\Expr\New_ $node, string $class): bool
    {
        if (! $node->class instanceof Node\Name) {
            return false;
        }

        $lowered = $node->class->toLowerString();

        return $lowered === 'self'
            || $lowered === 'static'
            || $lowered === strtolower(ltrim($class, '\\'));
    }
}
