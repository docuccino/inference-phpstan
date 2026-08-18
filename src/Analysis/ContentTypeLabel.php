<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * The media type a helper re-labelled its response with: `$response->headers->set('Content-Type',
 * '<literal>')` on the very variable it goes on to return. That's the shape an app takes when the response
 * is built by something that can't carry constructor headers — spatie's `toResponse()`, say — and without
 * reading it the body would be documented under the wrong media type.
 *
 * The window is what makes it honest: only header writes between the returned variable's last assignment
 * and the return itself count. Branches conventionally reuse `$response`, so matching by name alone would
 * hand one branch's label to another branch's body. A non-variable return, a computed header name or a
 * computed value all leave the media type alone rather than guessing.
 *
 * @internal
 */
final class ContentTypeLabel
{
    /**
     * @param  array<Node\Stmt>  $statements  the enclosing function's statements
     */
    public static function of(array $statements, Node\Expr $returned): ?string
    {
        if (! $returned instanceof Node\Expr\Variable || ! is_string($returned->name)) {
            return null;
        }

        $name = $returned->name;
        $until = $returned->getStartFilePos();
        $from = self::lastAssignmentBefore($statements, $name, $until);

        $label = null;
        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            $position = $call->getStartFilePos();
            if ($position > $from && $position < $until) {
                // Later writes win: a helper that overwrites the label ships the last one it set.
                $label = self::headerValue($call, $name) ?? $label;
            }
        }

        return $label;
    }

    /**
     * The position of the last `$<name> = …` before the return, or -1 when the variable is never assigned.
     *
     * @param  array<Node\Stmt>  $statements
     */
    private static function lastAssignmentBefore(array $statements, string $name, int $until): int
    {
        $from = -1;

        foreach ((new NodeFinder)->findInstanceOf($statements, Node\Expr\Assign::class) as $assign) {
            $position = $assign->getStartFilePos();
            if ($position < $until && $assign->var instanceof Node\Expr\Variable && $assign->var->name === $name) {
                $from = max($from, $position);
            }
        }

        return $from;
    }

    /**
     * `$<name>->headers->set('Content-Type', '<literal>')` → the literal, else null. A first-class callable
     * (`…->set(...)`) writes no header, and has no arguments to read.
     */
    private static function headerValue(Node\Expr\MethodCall $call, string $name): ?string
    {
        if (! $call->name instanceof Node\Identifier
            || $call->name->toString() !== 'set'
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $bag = $call->var;
        if (! $bag instanceof Node\Expr\PropertyFetch
            || ! $bag->name instanceof Node\Identifier
            || $bag->name->toString() !== 'headers'
            || ! $bag->var instanceof Node\Expr\Variable
            || $bag->var->name !== $name) {
            return null;
        }

        $args = $call->getArgs();
        $header = $args[0]->value ?? null;
        $value = $args[1]->value ?? null;

        return $header instanceof Node\Scalar\String_
            && strtolower($header->value) === 'content-type'
            && $value instanceof Node\Scalar\String_
            ? $value->value
            : null;
    }
}
