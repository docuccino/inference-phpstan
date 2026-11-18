<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use ReflectionClass;

/**
 * The private methods a class declares that a body calls on `$this`, `self` or `static`: code the method
 * writes for itself, which no subclass can stand in for. What {@see ThrowAnalyzer} reads one hop into for
 * the status a declared exception is built with, and nothing further.
 *
 * @internal
 */
final class PrivateHelpers
{
    /**
     * @param  array<Node>  $statements
     * @return list<string>
     */
    public static function called(string $class, array $statements): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        $finder = new NodeFinder;
        $names = [];

        $calls = [
            ...array_map(
                static fn (MethodCall $call): array => [$call->var instanceof Variable && $call->var->name === 'this', $call->name],
                $finder->findInstanceOf($statements, MethodCall::class),
            ),
            ...array_map(
                static fn (StaticCall $call): array => [$call->class instanceof Name && in_array($call->class->toLowerString(), ['self', 'static'], true), $call->name],
                $finder->findInstanceOf($statements, StaticCall::class),
            ),
        ];

        foreach ($calls as [$onSelf, $name]) {
            if (! $onSelf || ! $name instanceof Identifier || ! $reflection->hasMethod($name->toString())) {
                continue;
            }

            $method = $reflection->getMethod($name->toString());
            if ($method->isPrivate() && $method->getDeclaringClass()->getName() === $reflection->getName()) {
                $names[$method->getName()] = true;
            }
        }

        $helpers = array_map(strval(...), array_keys($names));
        sort($helpers, SORT_STRING);

        return $helpers;
    }
}
