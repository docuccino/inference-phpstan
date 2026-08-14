<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * The pure, AST-only decision logic behind enum-accessor folding, split out of
 * {@see ResponseShapeRefiner} and {@see EnumAccessorFolder} so it is unit-testable in process — anything
 * needing a PHPStan {@see Scope} stays in the engine and is covered by the fixture suites. Name resolution
 * (`self`/aliases → FQCN) is threaded in as a closure, so callers pass `$scope->resolveName(...)` and tests
 * pass a fake.
 *
 * @internal
 */
final class AccessorExtractor
{
    /**
     * Classify a value expression as an accessor on one of the current parameters: the parameter itself,
     * `$param->value`/`$param->name`, or a no-arg `$param->method()`. Null when it isn't rooted in one.
     *
     * A null-coalesce reads through its LEFT side (`$errors ?? $fallback` reads `$errors`): the fallback only
     * shows up when the argument was absent, which is exactly the case where binding finds no argument and
     * drops the value rather than pinning it.
     *
     * @param  list<string>  $paramNames
     */
    public static function fromExpr(Node\Expr $expr, array $paramNames): ?ParamAccessor
    {
        while ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            $expr = $expr->left;
        }

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
     * Provenance for each string-literal-keyed member of a response-body array whose value reads off a
     * parameter. A computed key is skipped — it isn't a stable member to document — which keeps this
     * Scope-free.
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
     * Re-home an accessor one hop out when the argument is a caller parameter — from out there the value
     * reads through the same accessor on the caller's own parameter. Null otherwise.
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
     * The enum case an `Enum::Case` constant-fetch names, or null when the expression isn't one.
     * `$resolveName` maps a parser `Name` to its FQCN.
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
     * The body of the `match ($this)` arm for `$caseName` — the arm naming the case, else `default` — or
     * null when the subject isn't `$this` or no arm applies. `$resolveName` lets `self::Case` resolve.
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
