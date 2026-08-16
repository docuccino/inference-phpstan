<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Lint\SensitiveFieldLintOptions;
use PhpParser\Node;

/**
 * Whether a constant expression is *named* like a credential, so the refiner can refuse to fold it.
 * `self::SIGNING_KEY` is a perfectly good constant string, and a folded literal becomes a published
 * `example` — which the emitter keeps, unlike provenance — so a truthful placeholder beats a real
 * secret.
 *
 * Matches against core's default name table only, never per-document lint config: an inferred type
 * that moved with a config key the fragment cache doesn't hash would be unsound.
 */
final class SensitiveConstant
{
    /** The label of the heuristic the constant's name matches, or null when it isn't a risky constant. */
    public static function label(Node\Expr $expr): ?string
    {
        foreach (self::operands($expr) as $operand) {
            $label = self::label($operand);

            if ($label !== null) {
                return $label;
            }
        }

        $name = self::constantName($expr);

        return $name === null ? null : (new SensitiveFieldLintOptions)->match($name);
    }

    /**
     * The sub-expressions whose value can reach the folded literal, so the guard is asked of each: a
     * concatenation publishes both operands, and `A ?? B` / `$c ? A : B` publish one or the other — which
     * one is exactly what the fold can't know, so either naming a credential is enough to refuse.
     *
     * @return list<Node\Expr>
     */
    private static function operands(Node\Expr $expr): array
    {
        if ($expr instanceof Node\Expr\BinaryOp\Concat || $expr instanceof Node\Expr\BinaryOp\Coalesce) {
            return [$expr->left, $expr->right];
        }

        if ($expr instanceof Node\Expr\Ternary) {
            // `$c ?: B` has no middle arm — the condition itself is what renders.
            return [$expr->if ?? $expr->cond, $expr->else];
        }

        return [];
    }

    /** The trailing identifier of a class constant or a global constant fetch; null for anything else. */
    private static function constantName(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\ClassConstFetch) {
            return $expr->name instanceof Node\Identifier ? $expr->name->toString() : null;
        }

        return $expr instanceof Node\Expr\ConstFetch ? $expr->name->getLast() : null;
    }
}
