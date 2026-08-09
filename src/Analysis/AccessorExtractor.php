<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * The PURE decision logic behind enum-accessor folding — the parts that read an AST shape rather than a
 * PHPStan {@see Scope}, factored out of {@see ResponseShapeRefiner} and
 * {@see EnumAccessorFolder} so they are unit-testable in process (the Scope-dependent parts — constant
 * folding, type resolution — stay in the engine and are proven by the --group=fixture suites). Any name
 * resolution a decision genuinely needs (`self`/aliases → FQCN) is threaded in as a closure so callers
 * pass `$scope->resolveName(...)` and tests pass a fake resolver.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class AccessorExtractor
{
    /**
     * Classify a value expression as an accessor on one of the current parameters: the parameter itself
     * (identity), `$param->value` / `$param->name`, or a NO-ARG method `$param->method()`. Null when the
     * value is not rooted in a parameter.
     *
     * @param  list<string>  $paramNames
     */
    public static function fromExpr(Node\Expr $expr, array $paramNames): ?ParamAccessor
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && in_array($expr->name, $paramNames, true)) {
            return ParamAccessor::identity($expr->name);
        }

        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && in_array($expr->var->name, $paramNames, true)
            && $expr->name instanceof Node\Identifier
        ) {
            $kind = match ($expr->name->toString()) {
                'value' => AccessorKind::Value,
                'name' => AccessorKind::Name,
                default => null,
            };

            return $kind === null ? null : new ParamAccessor($expr->var->name, $kind);
        }

        if ($expr instanceof Node\Expr\MethodCall
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && in_array($expr->var->name, $paramNames, true)
            && $expr->name instanceof Node\Identifier
            && $expr->getArgs() === []
        ) {
            return new ParamAccessor($expr->var->name, AccessorKind::Method, $expr->name->toString());
        }

        return null;
    }

    /**
     * The member→accessor provenance of a response-body array literal: each STRING-LITERAL-keyed member
     * whose value reads off one of the current parameters ({@see fromExpr}). Keys are read straight from
     * the AST (a documentable member always has a literal key; a computed key is skipped — it is not a
     * stable member to document), so this is Scope-free and unit-testable.
     *
     * @param  list<string>  $paramNames
     * @return array<string, ParamAccessor>
     */
    public static function provenanceFromArray(Node\Expr\Array_ $array, array $paramNames): array
    {
        $provenance = [];
        foreach ($array->items as $item) {
            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }
            $accessor = self::fromExpr($item->value, $paramNames);
            if ($accessor !== null) {
                $provenance[$item->key->value] = $accessor;
            }
        }

        return $provenance;
    }

    /**
     * Re-home an accessor one hop out when the argument is a caller PARAMETER: from the caller's vantage
     * the value reads through the same accessor on the caller's own parameter. Null otherwise.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    public static function rehome(Node\Expr $argExpr, ParamAccessor $accessor, array $paramNames): ?ParamAccessor
    {
        if ($argExpr instanceof Node\Expr\Variable && is_string($argExpr->name) && in_array($argExpr->name, $paramNames, true)) {
            return $accessor->withParam($argExpr->name);
        }

        return null;
    }

    /**
     * The concrete enum case a `Enum::Case` constant-fetch names, as `{fqcn, case}`, or null when the
     * expression is not an enum-case class-constant. `$resolveName` maps a parser `Name` to its FQCN.
     *
     * @param  Closure(Node\Name): string  $resolveName
     * @return array{fqcn: string, case: string}|null
     */
    public static function enumCaseFromConstFetch(Node\Expr $expr, Closure $resolveName): ?array
    {
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) !== 'class'
        ) {
            $fqcn = $resolveName($expr->class);
            if (enum_exists($fqcn)) {
                return ['fqcn' => $fqcn, 'case' => $expr->name->toString()];
            }
        }

        return null;
    }

    /**
     * The body expression of the `match ($this)` arm selected for `$caseName` — the arm whose condition
     * names the case, else the `default` arm — or null when the subject is not `$this` or no arm applies.
     * `$resolveName` maps a condition's `Name` to its FQCN (so `self::Case` resolves to the enum).
     *
     * @param  Closure(Node\Name): string  $resolveName
     */
    public static function matchArmBodyForCase(Node\Expr\Match_ $match, string $enumFqcn, string $caseName, Closure $resolveName): ?Node\Expr
    {
        if (! $match->cond instanceof Node\Expr\Variable || $match->cond->name !== 'this') {
            return null;
        }

        $default = null;
        foreach ($match->arms as $arm) {
            if ($arm->conds === null) {
                $default = $arm->body;

                continue;
            }
            foreach ($arm->conds as $cond) {
                if (self::condNamesCase($cond, $enumFqcn, $caseName, $resolveName)) {
                    return $arm->body;
                }
            }
        }

        return $default;
    }

    /**
     * @param  Closure(Node\Name): string  $resolveName
     */
    private static function condNamesCase(Node\Expr $cond, string $enumFqcn, string $caseName, Closure $resolveName): bool
    {
        return $cond instanceof Node\Expr\ClassConstFetch
            && $cond->class instanceof Node\Name
            && $cond->name instanceof Node\Identifier
            && $cond->name->toString() === $caseName
            && $resolveName($cond->class) === $enumFqcn;
    }
}
