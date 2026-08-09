<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Trace\TypeScopeImpl;
use PHPStan\Type\Type;

/**
 * The one constant-scalar fold, shared by every site that pins a value to a literal — the refiner, the enum
 * accessor folder, and the {@see TypeScopeImpl} const-value tail. A constant string is preferred first, so a
 * bound member reads identically to a directly-written string literal, then any single constant scalar.
 * Determinism is a product invariant, so copies of this that must stay bit-identical are a hazard: keep one.
 *
 * @internal
 */
final class ScalarFold
{
    /**
     * The constant scalar a type folds to, in a 1-tuple so a folded `null` stays distinct from "nothing
     * folded"; null when it isn't a single constant scalar. Callers emitting a {@see LiteralT} (never
     * null-valued) re-check `is_scalar`; the const-value tail keeps a folded `null` verbatim.
     *
     * @return array{0: string|int|float|bool|null}|null
     */
    public static function of(Type $type): ?array
    {
        $strings = $type->getConstantStrings();
        if (count($strings) === 1) {
            return [$strings[0]->getValue()];
        }

        if ($type->isConstantScalarValue()->yes()) {
            $values = $type->getConstantScalarValues();
            if (count($values) === 1) {
                return [$values[0]];
            }
        }

        return null;
    }
}
