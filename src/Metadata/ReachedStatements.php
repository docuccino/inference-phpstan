<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;

/**
 * The top-level statements of a constructor that every path completing it runs: those before the first
 * statement holding a `return` or a `goto`. A path that throws or never returns builds no object, so it
 * leaves nothing out. The one reading both the fixed-value and the initialisation readers take of
 * "assigned at top" and of "`parent::__construct()` runs".
 *
 * @internal
 */
final class ReachedStatements
{
    /**
     * @param  array<Node>  $statements
     * @return list<Node\Stmt>
     */
    public static function of(array $statements): array
    {
        $finder = new NodeFinder;
        $reached = [];
        foreach ($statements as $statement) {
            if (! $statement instanceof Node\Stmt
                || $finder->findFirst($statement, static fn (Node $node): bool => $node instanceof Node\Stmt\Return_ || $node instanceof Node\Stmt\Goto_) !== null
            ) {
                break;
            }

            $reached[] = $statement;
        }

        return $reached;
    }

    /** The value a `$this->name = …;` statement assigns, or null where it is not one. */
    public static function assignment(Node\Stmt $statement, string $name): ?Node\Expr
    {
        $written = self::propertyWrite($statement);

        return $written !== null && $written[0] === $name ? $written[1] : null;
    }

    /**
     * `[name, value]` where the statement is `$this->name = value;`.
     *
     * @return array{string, Node\Expr}|null
     */
    public static function propertyWrite(Node\Stmt $statement): ?array
    {
        if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\Assign) {
            return null;
        }

        $name = self::thisProperty($statement->expr->var);

        return $name === null ? null : [$name, $statement->expr->expr];
    }

    /** The name, where the expression is `$this->name`. */
    public static function thisProperty(Node\Expr $expr): ?string
    {
        return $expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
            ? $expr->name->toString()
            : null;
    }

    /**
     * The class declaring the constructor `parent::__construct()` runs, written in `$declaring`.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return ReflectionClass<object>|null
     */
    public static function parentConstructorClass(ReflectionClass $declaring): ?ReflectionClass
    {
        $parent = $declaring->getParentClass();

        return $parent === false ? null : $parent->getConstructor()?->getDeclaringClass();
    }

    /** The call, where the statement is `parent::__construct(…);`. */
    public static function parentConstruct(Node\Stmt $statement): ?Node\Expr\StaticCall
    {
        return $statement instanceof Node\Stmt\Expression
            && $statement->expr instanceof Node\Expr\StaticCall
            && $statement->expr->class instanceof Node\Name
            && $statement->expr->class->toLowerString() === 'parent'
            && $statement->expr->name instanceof Node\Identifier
            && $statement->expr->name->toLowerString() === '__construct'
            && ! $statement->expr->isFirstClassCallable()
            ? $statement->expr
            : null;
    }
}
