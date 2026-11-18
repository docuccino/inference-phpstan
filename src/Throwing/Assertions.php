<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Inference\PhpStan\Analysis\ParameterUse;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\FunctionReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Reflection\FunctionReflection;

/**
 * Whether a throw is reached only past a failed type test — an `instanceof`, an `is_*()` check, a test for
 * `null` (`=== null`, `??`) — which is the code telling the analyser what it already guarantees: the user a
 * route's middleware put there, the relation a foreign key keeps, the class a subclass is required to name.
 * A throw with no status behind one is an assertion ({@see ThrowSignal}); behind a test of a value, it is a
 * refusal the request can reach.
 *
 * The path is read whatever the throw's form, which is the invariant: a literal `throw` and a call to a
 * helper that throws are behind the guards {@see ParameterUse::guardsTo()} reads around them, and a
 * `throw_if()` or `throw_unless()` is behind its own condition too ({@see handed()}). What a helper throws is
 * behind every guard on the way to the call that reached it, which the analysis carries down.
 *
 * @internal
 */
final class Assertions
{
    /**
     * The type tests PHP spells as a function, and what each one returning true says: the value is of the type
     * the code goes on to use (`is_string`), or it is the `null` it is narrowed away from (`is_null`).
     * `is_numeric()` is not here: it reads a string's content, which a request decides.
     */
    public const TYPE_TESTS = [
        'is_array' => true,
        'is_bool' => true,
        'is_callable' => true,
        'is_countable' => true,
        'is_double' => true,
        'is_float' => true,
        'is_int' => true,
        'is_integer' => true,
        'is_iterable' => true,
        'is_long' => true,
        'is_null' => false,
        'is_object' => true,
        'is_resource' => true,
        'is_scalar' => true,
        'is_string' => true,
    ];

    /** Whether `$node`, a node of the body, is reached only where a type test on the way to it failed. */
    public static function behind(MethodReturnStatementsNode|FunctionReturnStatementsNode|ClosureReturnStatementsNode $body, Node $node): bool
    {
        return self::behindIn(
            array_values($body instanceof ClosureReturnStatementsNode ? $body->getClosureExpr()->stmts : $body->getStatements()),
            $node,
        );
    }

    /**
     * {@see behind()} over the nodes of a body — an arrow function's one expression.
     *
     * @param  list<Node>  $nodes
     */
    public static function behindIn(array $nodes, Node $node): bool
    {
        // The analyser hands a function's throw point a NORMALIZED copy of the call, so the node is found again
        // where it is written.
        $written = (new NodeFinder)->findFirst($nodes, static fn (Node $each): bool => $each::class === $node::class
            && $each->getStartFilePos() === $node->getStartFilePos()
            && $each->getEndFilePos() === $node->getEndFilePos());

        if ($written === null) {
            return false;
        }

        foreach (ParameterUse::guardsTo($written, $nodes) as [$condition, $holds]) {
            if (self::failsTypeTest($condition, $holds)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a `throw_if()` or `throw_unless()` throws only where the condition it is handed fails a type test. */
    public static function handed(FunctionReflection $function, Node\Expr\CallLike $call): bool
    {
        $guard = GuardCondition::of($function, $call);

        return $guard !== null && self::failsTypeTest(...$guard);
    }

    /**
     * Whether `$condition` holding `$holds` means a type test failed, on every way it can: `!` flips what must
     * hold, and a short circuit fails on every path where each of the operands that decide it does.
     */
    public static function failsTypeTest(Node\Expr $condition, bool $holds): bool
    {
        if ($condition instanceof Node\Expr\BooleanNot) {
            return self::failsTypeTest($condition->expr, ! $holds);
        }

        $and = $condition instanceof Node\Expr\BinaryOp\BooleanAnd || $condition instanceof Node\Expr\BinaryOp\LogicalAnd;
        $or = $condition instanceof Node\Expr\BinaryOp\BooleanOr || $condition instanceof Node\Expr\BinaryOp\LogicalOr;
        if ($and || $or) {
            $left = self::failsTypeTest($condition->left, $holds);
            $right = self::failsTypeTest($condition->right, $holds);

            // `a && b` true, or `a || b` false, holds of both operands, so either failing is enough; the other
            // two hold of one operand or the other, so both must.
            return $and === $holds ? $left || $right : $left && $right;
        }

        if ($condition instanceof Node\Expr\Instanceof_) {
            return ! $holds;
        }

        if ($condition instanceof Node\Expr\FuncCall && $condition->name instanceof Node\Name) {
            $passes = self::TYPE_TESTS[strtolower(ltrim($condition->name->toString(), '\\'))] ?? null;

            return $passes !== null && $holds !== $passes;
        }

        if ($condition instanceof Node\Expr\BinaryOp\Identical || $condition instanceof Node\Expr\BinaryOp\NotIdentical) {
            $null = self::isNull($condition->left) || self::isNull($condition->right);

            return $null && $holds === $condition instanceof Node\Expr\BinaryOp\Identical;
        }

        return false;
    }

    private static function isNull(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'null';
    }
}
