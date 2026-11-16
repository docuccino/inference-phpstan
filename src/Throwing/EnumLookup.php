<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\FunctionReflection;
use TypeError;
use ValueError;

/**
 * What `from()` on a backed enum can throw where its argument is a constant: a `ValueError` only for a value
 * no case is backed by, a `TypeError` only for one PHP refuses as the backing type. The values are the ones
 * the analyser pins the argument to, matched to the cases strictly, as its own `from()` return type matches
 * them — so the case a status read finds there is the case this read finds.
 *
 * @internal
 */
final class EnumLookup
{
    /**
     * The enum a call looks a case up on, what the lookup can throw, each class with whether every value
     * throws it, and whether every value throws something; null where the call is not `from()` on a backed
     * enum with a constant argument, and what it declares stands.
     *
     * @return array{class-string, array<string, bool>, bool}|null
     */
    public static function of(Node $node, Scope $scope, FunctionReflection|ExtendedMethodReflection|null $reached): ?array
    {
        if (! $reached instanceof ExtendedMethodReflection || strtolower($reached->getName()) !== 'from') {
            return null;
        }

        $argument = self::argument($node);
        $type = $argument === null ? null : $scope->getType($argument);
        if ($type === null || ! $type->isConstantScalarValue()->yes()) {
            return null;
        }

        $enum = $reached->getDeclaringClass();
        $backingType = $enum->getBackedEnumType();
        if (! $enum->isBackedEnum() || $backingType === null) {
            return null;
        }

        $backing = [];
        foreach ($enum->getEnumCases() as $case) {
            $value = $case->getBackingValueType()?->getConstantScalarValues() ?? [];
            if (count($value) !== 1) {
                return null;
            }
            $backing[] = $value[0];
        }

        $throws = self::throws($type->getConstantScalarValues(), $backing, $backingType->isInteger()->yes(), $scope->isDeclareStrictTypes());

        return $throws === null ? null : [$enum->getName(), ...$throws];
    }

    /**
     * What `from()` throws over every value its argument may be, each class with whether every value throws it,
     * and whether every value throws something. A value of the backing type finds its case or throws
     * `ValueError`; any other is a `TypeError` in a file declaring `strict_types`, and coerced elsewhere by
     * rules this read does not repeat, so it answers null, as it does for no value at all.
     *
     * @param  list<mixed>  $values
     * @param  list<mixed>  $backing
     * @return array{array<string, bool>, bool}|null
     */
    public static function throws(array $values, array $backing, bool $intBacked, bool $strict): ?array
    {
        $thrown = self::thrown($values, $backing, $intBacked, $strict);

        return $thrown === null ? null : [self::tally($thrown), ! in_array(null, $thrown, true)];
    }

    /**
     * What each value throws, null for one that finds its case; null for no value, or one PHP coerces.
     *
     * @param  list<mixed>  $values
     * @param  list<mixed>  $backing
     * @return list<string|null>|null
     */
    private static function thrown(array $values, array $backing, bool $intBacked, bool $strict): ?array
    {
        if ($values === []) {
            return null;
        }

        $thrown = [];
        foreach ($values as $value) {
            if ($intBacked ? is_int($value) : is_string($value)) {
                $thrown[] = in_array($value, $backing, true) ? null : ValueError::class;
            } elseif ($strict) {
                $thrown[] = TypeError::class;
            } else {
                return null;
            }
        }

        return $thrown;
    }

    /**
     * @param  list<string|null>  $thrown
     * @return array<string, bool>
     */
    private static function tally(array $thrown): array
    {
        $outcomes = [];
        foreach (array_filter($thrown) as $class) {
            $outcomes[$class] = count(array_keys($thrown, $class, true)) === count($thrown);
        }
        ksort($outcomes);

        return $outcomes;
    }

    /** The argument bound to `$value`: the first written, or one named for it. */
    private static function argument(Node $node): ?Node\Expr
    {
        if (! $node instanceof Node\Expr\CallLike || $node->isFirstClassCallable()) {
            return null;
        }

        foreach ($node->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }
            if ($arg->name === null ? $index === 0 : $arg->name->toString() === 'value') {
                return $arg->value;
            }
        }

        return null;
    }
}
