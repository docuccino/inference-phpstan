<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\UnionT;

/**
 * What makes one return site of a narrowed renderer reachable, as ALTERNATIVES OF REQUIRED classes: a
 * union alternates, an intersection requires, and an empty guard is the default branch that anything
 * reaches.
 *
 * The shape is the point. A guard has to read the same grammar as the runtime test it stands for:
 * `$e instanceof HttpException && $e instanceof HasErrors` admits a type only if it is BOTH, and a flat
 * list of names cannot tell that from `HttpException|HasErrors`, which admits either — so a throttle
 * would be answered with the validation arm's body, under the validation arm's name.
 *
 * @internal
 */
final class NarrowingGuard
{
    /**
     * The guard a return site's narrowed parameter type states.
     *
     * @return list<list<string>>
     */
    public static function ofType(DType $type): array
    {
        if ($type instanceof ClassT) {
            return [[$type->fqcn]];
        }

        if ($type instanceof UnionT) {
            $out = [];
            foreach ($type->members as $member) {
                $out = [...$out, ...self::ofType($member)];
            }

            return $out;
        }

        if ($type instanceof IntersectionT) {
            $out = [];
            foreach ($type->members as $member) {
                $out = self::allOf($out, self::ofType($member));
            }

            return $out;
        }

        return [];
    }

    /**
     * Two guards that must BOTH hold: every pairing of one side's alternatives with the other's. A side
     * carrying no `instanceof` information gates nothing, so it leaves the other side as it was rather
     * than emptying it.
     *
     * @param  list<list<string>>  $left
     * @param  list<list<string>>  $right
     * @return list<list<string>>
     */
    public static function allOf(array $left, array $right): array
    {
        if ($left === [] || $right === []) {
            return $left === [] ? $right : $left;
        }

        $out = [];
        foreach ($left as $one) {
            foreach ($right as $other) {
                $out[] = array_values(array_unique([...$one, ...$other]));
            }
        }

        return $out;
    }

    /**
     * Two guards where EITHER may hold: both sides' alternatives, side by side. A side carrying no
     * `instanceof` information is a side anything satisfies, so it makes the whole guard broad — the
     * mirror of {@see allOf()}, where such a side leaves the other one alone. An arm gated on
     * `$e instanceof A || $e->isFatal()` is reached by a fatal B, and reading it as "A only" would answer
     * that B with a later arm's body.
     *
     * @param  list<list<string>>  $left
     * @param  list<list<string>>  $right
     * @return list<list<string>>
     */
    public static function anyOf(array $left, array $right): array
    {
        if ($left === [] || $right === []) {
            return [];
        }

        return [...$left, ...$right];
    }

    /**
     * Whether the narrowed type reaches this return: one alternative all of whose classes it satisfies.
     *
     * @param  list<list<string>>  $guard
     */
    public static function satisfiedBy(array $guard, string $narrowTo): bool
    {
        if ($guard === []) {
            return true;
        }

        foreach ($guard as $alternative) {
            $all = true;
            foreach ($alternative as $fqcn) {
                if ($narrowTo !== $fqcn && ! is_a($narrowTo, $fqcn, true)) {
                    $all = false;

                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the guard tests the narrowed class BY NAME — the "this arm is about exactly this exception"
     * question, which a guard naming only a base or a marker interface answers no to.
     *
     * @param  list<list<string>>  $guard
     */
    public static function namesExactly(array $guard, string $narrowTo): bool
    {
        foreach ($guard as $alternative) {
            if (in_array($narrowTo, $alternative, true)) {
                return true;
            }
        }

        return false;
    }
}
