<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use PhpParser\Node;

/**
 * Names the arguments of a `new Foo(...)`, pairing positional ones with the constructor's parameter names
 * so a written `new Foo($a, $b)` and a written `new Foo(b: $b, a: $a)` describe the same members. A spread
 * (`...$args`) names nothing, so the arguments after it are left out rather than mis-attributed.
 *
 * @internal
 */
final class ConstructorArgs
{
    /**
     * Argument name → its value expression, in written order.
     *
     * @param  list<string>  $paramNames  the constructor's parameter names, in declaration order
     * @return array<string, Node\Expr>
     */
    public static function named(Node\Expr\New_ $new, array $paramNames): array
    {
        $named = [];
        $position = 0;

        foreach ($new->getArgs() as $arg) {
            if ($arg->unpack) {
                break;
            }

            if ($arg->name instanceof Node\Identifier) {
                $named[$arg->name->toString()] = $arg->value;

                continue;
            }

            $name = $paramNames[$position++] ?? null;
            if ($name !== null) {
                $named[$name] = $arg->value;
            }
        }

        return $named;
    }
}
