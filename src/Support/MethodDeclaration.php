<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;

/**
 * Where a reflected method is written among its file's name-resolved statements, found by NAME: the class
 * that declares it, or a trait it uses, and then the method. Never by line — native reflection reports the
 * line of a method's `function` keyword and php-parser the line of its first attribute or modifier, so a
 * method carrying either on a line of its own would be found nowhere.
 *
 * @internal
 */
final class MethodDeclaration
{
    /**
     * @param  array<Node>  $statements  the file's statements, name-resolved
     */
    public static function in(array $statements, ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
        $finder = new NodeFinder;

        foreach (self::writers($method->getDeclaringClass()) as $writer) {
            $class = $finder->findFirst(
                $statements,
                static fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike && $node->namespacedName?->toString() === $writer,
            );
            $found = $class instanceof Node\Stmt\ClassLike ? $class->getMethod($method->getName()) : null;
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The declaring class and every trait it uses, however deep — PHP reports a trait's method as the
     * using class's, and only the trait's body writes it.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private static function writers(ReflectionClass $class): array
    {
        $names = [];
        $pending = [$class];
        while ($pending !== []) {
            $next = array_shift($pending);
            if (in_array($next->getName(), $names, true)) {
                continue;
            }
            $names[] = $next->getName();
            foreach ($next->getTraits() as $trait) {
                $pending[] = $trait;
            }
        }

        return $names;
    }
}
