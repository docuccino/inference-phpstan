<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Trace\TypeScopeImpl;
use PHPStan\Type\Type;

/**
 * The single Scope-based constant-scalar fold shared by every engine site that pins a value to a
 * literal: the response-shape refiner's `constLiteralOf`/`intLiteralOf`, the enum accessor folder's
 * `constLiteral`, and the {@see TypeScopeImpl} const-value tail. A
 * constant STRING is preferred first (so a bound member reads identically to a directly-written string
 * literal), then any single constant scalar value. Because determinism is a product invariant, keeping
 * ONE implementation removes the bit-drift hazard of copies that must stay identical.
 *
 * @internal Engine implementation detail — not part of the public inference surface.
 */
final class ScalarFold
{
    /**
     * The single constant scalar a type folds to, wrapped in a 1-tuple so a folded `null` is distinct
     * from "nothing folded"; null when the type is not a single constant scalar. Callers that emit a
     * {@see LiteralT} (which is never null-valued) re-check `is_scalar`
     * on the tuple value; the const-value tail keeps a folded `null` verbatim.
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
