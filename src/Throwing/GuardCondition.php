<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;

/**
 * Laravel's conditional throwers — `abort_if()`, `abort_unless()`, `throw_if()`, `throw_unless()` — and
 * whether one can never throw where it is called: its condition, the first argument, is always the value it
 * does not throw on. Read off the condition's native type, which PHP enforces, so a docblock that
 * narrows it too far cannot drop an error the request really gets.
 *
 * @internal
 */
final class GuardCondition
{
    /** Each helper, and whether a truthy condition is the one it throws on. */
    private const THROWS_WHEN_TRUTHY = [
        'abort_if' => true,
        'abort_unless' => false,
        'throw_if' => true,
        'throw_unless' => false,
    ];

    public static function neverThrows(FunctionReflection $function, Node\Expr\CallLike $call, Scope $scope): bool
    {
        $guard = self::of($function, $call);
        if ($guard === null) {
            return false;
        }

        [$condition, $truthy] = $guard;
        $value = $scope->getNativeType($condition)->toBoolean();

        return $truthy ? $value->isFalse()->yes() : $value->isTrue()->yes();
    }

    /**
     * The condition a call to one of the helpers is handed, and the value it holds wherever the call throws:
     * true for `throw_if()`, false for `throw_unless()`. Null for any other call, or one whose condition is
     * not written at it.
     *
     * @return array{Node\Expr, bool}|null
     */
    public static function of(FunctionReflection $function, Node\Expr\CallLike $call): ?array
    {
        $truthy = self::THROWS_WHEN_TRUTHY[strtolower($function->getName())] ?? null;
        if ($truthy === null || $call->isFirstClassCallable()) {
            return null;
        }

        $names = [];
        foreach ($function->getVariants()[0]->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        $condition = ArgumentSlots::of($call->getArgs(), $names)->at(0);

        return $condition === null ? null : [$condition, $truthy];
    }
}
