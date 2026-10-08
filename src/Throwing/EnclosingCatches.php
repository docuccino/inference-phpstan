<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Closure;
use PhpParser\Node;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;

/**
 * The classes every `catch` around one offset of a body names, read off the source rather than off the
 * analyser's point, whose type for an undeclared call is not PHP's rule: a catch takes anything the try's own
 * statements raise that is an instance of a class it names. A catch or `finally` body is outside its own try,
 * and a nested function or class is a boundary.
 *
 * A catch takes nothing where what it caught may leave it ({@see escapes()}): a `throw` of its variable, or
 * the variable handed to a call or stored, unless `$keeps` proves that callee keeps it. Reading a throw that
 * leaves as taken publishes less than the server sends; the other mistake is only vague.
 *
 * @phpstan-type Keeps Closure(Node\Expr\CallLike, int): bool
 *
 * @internal
 */
final class EnclosingCatches
{
    /**
     * The methods a `Throwable` is read through without being handed on: final on `Exception` and `Error`,
     * so no class can make one of them throw the exception it is called on.
     */
    public const ACCESSORS = ['getmessage', 'getcode', 'getfile', 'getline', 'gettrace', 'getprevious', 'gettraceasstring'];

    /** Reads of a variable that name no use of it: by position, or every local at once. */
    private const OPAQUE_READS = ['func_get_args', 'func_get_arg', 'get_defined_vars', 'compact', 'extract'];

    /**
     * @param  Keeps|null  $keeps
     * @return list<Node\Name> empty where the offset is unknown
     */
    public static function of(MethodReturnStatementsNode|ClosureReturnStatementsNode $body, Node $node, ?Closure $keeps = null): array
    {
        return self::around(self::statements($body), $node->getStartFilePos(), $keeps);
    }

    /**
     * The places a catch around the node lets out what it caught ({@see escapes()}). The analyser drops a
     * point under a catch wide enough to take all it can name, so where a rethrow cannot spell what it lets
     * out, nothing else says so.
     *
     * @param  Keeps|null  $keeps
     * @return list<Node\Expr\Throw_|Node\Expr\CallLike|Node\Expr\Assign|Node\Expr\AssignRef>
     */
    public static function rethrowsAround(MethodReturnStatementsNode|ClosureReturnStatementsNode $body, Node $node, ?Closure $keeps = null): array
    {
        return self::rethrowsAt(self::statements($body), $node->getStartFilePos(), $keeps);
    }

    /**
     * @param  array<Node>  $nodes
     * @param  Keeps|null  $keeps
     * @return list<Node\Expr\Throw_|Node\Expr\CallLike|Node\Expr\Assign|Node\Expr\AssignRef>
     */
    public static function rethrowsAt(array $nodes, int $offset, ?Closure $keeps = null): array
    {
        $rethrows = [];
        foreach (self::catchesAt($nodes, $offset) as $catch) {
            foreach (self::escapes($catch, $keeps) as $rethrow) {
                $rethrows[] = $rethrow;
            }
        }

        return $rethrows;
    }

    /**
     * Every call and `throw` the body makes inside a `try` block, in source order. Where a catch took all the
     * analyser said one raises it may leave no point at all, yet a closure a call was handed still runs, and
     * a rethrowing catch still lets out what it took.
     *
     * @return list<Node\Expr\CallLike|Node\Expr\Throw_>
     */
    public static function guarded(MethodReturnStatementsNode|ClosureReturnStatementsNode $body): array
    {
        return self::guardedIn(self::statements($body));
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Expr\CallLike|Node\Expr\Throw_>
     */
    public static function guardedIn(array $nodes): array
    {
        $calls = [];
        foreach ($nodes as $node) {
            self::collect($node, false, $calls);
        }

        return $calls;
    }

    /**
     * @param  array<Node>  $nodes
     * @param  Keeps|null  $keeps
     * @return list<Node\Name>
     */
    public static function around(array $nodes, int $offset, ?Closure $keeps = null): array
    {
        $names = [];
        foreach (self::catchesAt($nodes, $offset) as $catch) {
            if (self::escapes($catch, $keeps) === []) {
                foreach ($catch->types as $type) {
                    $names[] = $type;
                }
            }
        }

        return $names;
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Stmt\Catch_> outermost first
     */
    private static function catchesAt(array $nodes, int $offset): array
    {
        return $offset < 0 ? [] : (self::walk($nodes, $offset) ?? []);
    }

    /**
     * @param  array<Node>  $nodes
     * @return list<Node\Stmt\Catch_>|null outermost first; null where the offset sits behind a function or class boundary
     */
    private static function walk(array $nodes, int $offset): ?array
    {
        foreach ($nodes as $node) {
            if (self::covers([$node], $offset)) {
                return self::within($node, $offset);
            }
        }

        return [];
    }

    /** @return list<Node\Stmt\Catch_>|null */
    private static function within(Node $node, int $offset): ?array
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return null;
        }

        if ($node instanceof Node\Stmt\TryCatch) {
            if (! self::covers($node->stmts, $offset)) {
                return self::walk($node->finally === null ? $node->catches : [...$node->catches, $node->finally], $offset);
            }

            $inner = self::walk($node->stmts, $offset);
            if ($inner === null) {
                return null;
            }

            return [...array_values($node->catches), ...$inner];
        }

        $children = [];
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $children[] = $child;
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $children[] = $item;
                    }
                }
            }
        }

        return self::walk($children, $offset);
    }

    /**
     * Whether the nodes read a variable as a value that may be handed on — anything but the receiver of one
     * of the {@see ACCESSORS} or the left of an `instanceof` — or read their locals in a way that names none.
     * The one grammar for both sides of a hand-off: what a catch passes on, and what the callee it is passed
     * to does with the parameter. A closure written in the nodes is read, since it sees the variable through
     * `use` or as an arrow function; a function or class is not.
     *
     * @param  array<Node>  $nodes
     */
    public static function readsWhole(array $nodes, string $name): bool
    {
        foreach ($nodes as $node) {
            if (self::readWhole($node, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function readWhole(Node $node, string $name): bool
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassLike) {
            return false;
        }

        if ($node instanceof Node\Expr\Variable) {
            return $node->name === $name || ! is_string($node->name);
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
            && in_array($node->name->toLowerString(), self::OPAQUE_READS, true)
        ) {
            return true;
        }

        $skip = null;
        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toLowerString(), self::ACCESSORS, true)
            && self::isVariable($node->var, $name)
        ) {
            $skip = $node->var;
        } elseif ($node instanceof Node\Expr\Instanceof_ && self::isVariable($node->expr, $name)) {
            $skip = $node->expr;
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $child = $node->{$sub};
            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node && $item !== $skip && self::readWhole($item, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isVariable(Node $node, string $name): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === $name;
    }

    /**
     * Every place the very exception the catch caught may leave it, on any path: a `throw` of its variable,
     * a call it is passed to (unless `$keeps` proves that callee never lets it out), and an assignment that
     * stores it for something else to throw. What leaves through one is whatever the try raised, so the catch
     * has taken none of it. A `new` is not a hand-off: the wrapping idiom, `throw new B(previous: $e)`, lets
     * out what it builds. A closure written in the body may hold the variable too, and is read; a function
     * or class cannot, and is not.
     *
     * @param  Keeps|null  $keeps
     * @return list<Node\Expr\Throw_|Node\Expr\CallLike|Node\Expr\Assign|Node\Expr\AssignRef>
     */
    private static function escapes(Node\Stmt\Catch_ $catch, ?Closure $keeps): array
    {
        if ($catch->var === null || ! is_string($catch->var->name)) {
            return [];
        }

        $found = [];
        foreach ($catch->stmts as $statement) {
            self::collectEscapes($statement, $catch->var->name, $keeps, $found);
        }

        return $found;
    }

    /**
     * @param  Keeps|null  $keeps
     * @param  list<Node\Expr\Throw_|Node\Expr\CallLike|Node\Expr\Assign|Node\Expr\AssignRef>  $found
     */
    private static function collectEscapes(Node $node, string $name, ?Closure $keeps, array &$found): void
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassLike) {
            return;
        }

        if ($node instanceof Node\Expr\Throw_ && self::isVariable($node->expr, $name)) {
            $found[] = $node;
        } elseif (($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef) && self::readWhole($node->expr, $name)) {
            $found[] = $node;
        } elseif ($node instanceof Node\Expr\CallLike && ! $node instanceof Node\Expr\New_ && self::handsOn($node, $name, $keeps)) {
            $found[] = $node;
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $child = $node->{$sub};
            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node) {
                    self::collectEscapes($item, $name, $keeps, $found);
                }
            }
        }
    }

    /**
     * Whether a call is handed the variable by any argument a callee could let it out of: one that IS the
     * variable is kept only where `$keeps` says so, and one that merely holds it never is.
     *
     * @param  Keeps|null  $keeps
     */
    private static function handsOn(Node\Expr\CallLike $call, string $name, ?Closure $keeps): bool
    {
        if ($call->isFirstClassCallable()) {
            return false;
        }

        foreach ($call->getArgs() as $position => $argument) {
            if (! self::readWhole($argument->value, $name)) {
                continue;
            }

            if ($argument->unpack || ! self::isVariable($argument->value, $name) || $keeps === null || ! $keeps($call, $position)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<Node\Stmt> */
    private static function statements(MethodReturnStatementsNode|ClosureReturnStatementsNode $body): array
    {
        return $body instanceof MethodReturnStatementsNode
            ? $body->getStatements()
            : $body->getClosureExpr()->stmts;
    }

    /**
     * The same boundaries {@see within()} keeps: only a `try` block is guarded, and a nested function or
     * class is not entered.
     *
     * @param  list<Node\Expr\CallLike|Node\Expr\Throw_>  $calls
     */
    private static function collect(Node $node, bool $guarded, array &$calls): void
    {
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return;
        }

        if ($node instanceof Node\Stmt\TryCatch) {
            foreach ($node->stmts as $statement) {
                self::collect($statement, true, $calls);
            }
            foreach ($node->finally === null ? $node->catches : [...$node->catches, $node->finally] as $outside) {
                self::collect($outside, $guarded, $calls);
            }

            return;
        }

        if ($guarded && ($node instanceof Node\Expr\CallLike || $node instanceof Node\Expr\Throw_)) {
            $calls[] = $node;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node) {
                    self::collect($item, $guarded, $calls);
                }
            }
        }
    }

    /**
     * @param  array<Node>  $nodes
     */
    private static function covers(array $nodes, int $offset): bool
    {
        foreach ($nodes as $node) {
            if ($node->getStartFilePos() <= $offset && $offset <= $node->getEndFilePos()) {
                return true;
            }
        }

        return false;
    }
}
