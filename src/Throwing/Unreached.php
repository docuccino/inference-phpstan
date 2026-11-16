<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Closure;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;

/**
 * A call, construction, `throw` or `return` the code can never complete, because an operand PHP evaluates
 * before it always throws: a `throw` expression, a backed enum's `from()` that no value of its constant
 * argument backs a case for ({@see EnumLookup}), or a call or fetch made on or with one. Only unconditional
 * operands count — arguments and receivers — so a `??`, a ternary or a nullsafe call's arguments never do.
 *
 * @internal
 */
final class Unreached
{
    /**
     * Whether a call or construction never runs, or a `throw` never throws what it names, because one of its
     * operands always throws first.
     *
     * @param  Closure(class-string): void  $read  told each enum whose cases the answer was read off
     */
    public static function byOperand(Node $node, Scope $scope, Closure $read): bool
    {
        if ($node instanceof Node\Expr\Throw_) {
            return self::alwaysThrows($node->expr, $scope, $read);
        }

        if (! $node instanceof Node\Expr\CallLike || $node->isFirstClassCallable()) {
            return false;
        }

        if ($node instanceof Node\Expr\NullsafeMethodCall) {
            return self::alwaysThrows($node->var, $scope, $read);
        }

        $operands = $node instanceof Node\Expr\MethodCall ? [$node->var] : [];
        foreach ($node->getArgs() as $arg) {
            $operands[] = $arg->value;
        }

        foreach ($operands as $operand) {
            if (self::alwaysThrows($operand, $scope, $read)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether evaluating an expression always throws before it has a value.
     *
     * @param  Closure(class-string): void  $read
     */
    public static function alwaysThrows(Node\Expr $expr, Scope $scope, Closure $read): bool
    {
        if ($expr instanceof Node\Expr\Throw_) {
            return true;
        }

        if ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch) {
            return self::alwaysThrows($expr->var, $scope, $read);
        }

        if (! $expr instanceof Node\Expr\CallLike) {
            return false;
        }

        $lookup = EnumLookup::of($expr, $scope, self::method($expr, $scope));
        if ($lookup !== null) {
            $read($lookup[0]);
            if ($lookup[2]) {
                return true;
            }
        }

        return self::byOperand($expr, $scope, $read);
    }

    /** The method a static or instance call names, as the analyser resolves it in this scope. */
    private static function method(Node\Expr\CallLike $call, Scope $scope): ?ExtendedMethodReflection
    {
        if ($call instanceof Node\Expr\StaticCall && $call->name instanceof Node\Identifier) {
            $type = $call->class instanceof Node\Name ? $scope->resolveTypeByName($call->class) : $scope->getType($call->class);

            return $scope->getMethodReflection($type, $call->name->toString());
        }

        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier) {
            return $scope->getMethodReflection($scope->getType($call->var), $call->name->toString());
        }

        return null;
    }
}
