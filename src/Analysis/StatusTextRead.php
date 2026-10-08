<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\DType\LiteralT;
use PhpParser\Node;
use ReflectionException;
use ReflectionProperty;

/**
 * A read of Symfony's status-text table — `Response::$statusTexts[$code]`, optionally `?? $fallback` —
 * spelled through any class that inherits the table rather than declaring one of its own
 * (`JsonResponse::$statusTexts` is the same array). What the key is, and so whether the phrase is the one
 * the response is sent with, is the caller's question; this only recognises the read.
 *
 * Name resolution and fallback folding are threaded in, so the AST half is testable without an analyser.
 *
 * @internal
 */
final class StatusTextRead
{
    /** The class declaring the table every response class reads. */
    public const TABLE_CLASS = 'Symfony\\Component\\HttpFoundation\\Response';

    private const TABLE = 'statusTexts';

    /**
     * The key the table is read at, and the literal a `??` falls back to — null where there is no `??`, or
     * where what it falls back to does not fold. Whether the named class reads Symfony's table is answered
     * by its hierarchy, so each file of it is recorded through `$touch`, the table or not.
     *
     * @param  Closure(Node\Name): string  $resolveName
     * @param  Closure(Node\Expr): ?LiteralT  $fold
     * @param  Closure(string): void  $touch
     * @return array{key: Node\Expr, fallback: ?LiteralT}|null
     */
    public static function of(Node\Expr $expr, Closure $resolveName, Closure $fold, Closure $touch): ?array
    {
        $fallback = null;
        if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            $fallback = $fold($expr->right);
            $expr = $expr->left;
        }

        if (! $expr instanceof Node\Expr\ArrayDimFetch
            || $expr->dim === null
            || ! $expr->var instanceof Node\Expr\StaticPropertyFetch
            || ! $expr->var->class instanceof Node\Name
            || ! $expr->var->name instanceof Node\VarLikeIdentifier
            || $expr->var->name->toString() !== self::TABLE
        ) {
            return null;
        }

        $class = $resolveName($expr->var->class);
        foreach (DeclarationFiles::of($class) as $file) {
            $touch($file);
        }

        return self::readsTable($class) ? ['key' => $expr->dim, 'fallback' => $fallback] : null;
    }

    /** Whether the class's `$statusTexts` is Symfony's own static table, rather than one it redeclares. */
    public static function readsTable(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $property = new ReflectionProperty($class, self::TABLE);
        } catch (ReflectionException) {
            return false;
        }

        return $property->isStatic() && $property->getDeclaringClass()->getName() === self::TABLE_CLASS;
    }
}
