<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\LocalWrites;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * What the statements ahead of a returned local restate about the response it holds: each chain made on the
 * local (`$response->setStatusCode(202);`) is read as the fluent chain it would be ({@see FluentResponseChain})
 * and folded into the next in the order they run.
 *
 * Only a chain written as a statement straight in a block the return sits in runs on every path to it; one
 * inside a branch or a loop joins its status to the one before. Anything else that may change the object — a
 * chain this cannot read, a call that does not keep it ({@see StateKeepingCalls}), a write through it, a
 * closure capturing it — leaves the status, the media type and the body unknown.
 *
 * @phpstan-import-type PeeledChain from FluentResponseChain
 *
 * @phpstan-type Restated array{status: LiteralT|UnionT|null, statusUnknown: bool, contentType: string|null, contentTypeUnknown: bool, mayAlsoBe: list<LiteralT|UnionT>, body: DType|null, bodyUnknown: bool}
 *
 * @internal
 */
final class LocalRestatements
{
    /**
     * @param  Closure(Node\Expr\MethodCall): (PeeledChain|null)  $peel  the chain a call ends, read where it runs ({@see FluentResponseChain::peel()})
     * @param  Closure(int, ?Node\Expr\MethodCall): bool  $keeps  whether the call made on the local, or handed it, at an offset keeps the object
     */
    public function __construct(
        private readonly Closure $peel,
        private readonly Closure $keeps,
    ) {}

    /**
     * What the body restates about `$name` before offset `$at`, where the local is returned; null where it
     * restates nothing, so the response reads as it was built.
     *
     * @param  array<Node\Stmt>  $body
     * @return Restated|null
     */
    public function before(array $body, string $name, int $at): ?array
    {
        $statements = array_values($body);
        $within = (new NodeFinder)->findFirst($statements, static fn (Node $node): bool => $node instanceof Node\FunctionLike
            && $node->getStartFilePos() <= $at && $node->getEndFilePos() >= $at);
        if ($within !== null) {
            return null; // returned from an arrow function, whose one expression no statement precedes
        }

        $definite = [];
        self::markDefinite($statements, $at, $definite);

        $events = [];
        self::walk($statements, null, null, $name, $at, $definite, $events);

        $state = self::nothing();
        foreach ($events as [$kind, $node, $certain]) {
            if ($kind === 'replaced') {
                $state = self::nothing();

                continue;
            }
            if ($kind === 'written') {
                // Another value may stand in the local now, and nothing restated before it says which.
                $state = $state === self::nothing() ? $state : self::unread();

                continue;
            }

            $chain = $kind === 'chain' && $node instanceof Node\Expr\MethodCall ? ($this->peel)($node) : null;
            if ($chain !== null && self::rootOf($chain['receiver'], $name)) {
                $state = $certain ? self::over($state, $chain) : self::besides($state, $chain);
            } elseif (! $this->kept($kind, $node)) {
                $state = self::unread();
            }
        }

        return $state === self::nothing() ? null : $state;
    }

    /** Whether a call that is no chain this can read leaves the object as it was. */
    private function kept(string $kind, Node $node): bool
    {
        if ($kind === 'handed' && $node instanceof Node\Expr\Variable) {
            return ($this->keeps)($node->getStartFilePos(), null);
        }
        if ($kind !== 'chain' || ! $node instanceof Node\Expr\MethodCall) {
            return false; // a write through the object
        }

        // The call made straight on the local: a reader keeps it, and then nothing chained on what it
        // returns is a call on the response.
        $call = $node;
        while ($call->var instanceof Node\Expr\MethodCall) {
            $call = $call->var;
        }

        return $call->var instanceof Node\Expr\Variable && ($this->keeps)($call->var->getStartFilePos(), $call);
    }

    /** @return Restated */
    private static function nothing(): array
    {
        return ['status' => null, 'statusUnknown' => false, 'contentType' => null, 'contentTypeUnknown' => false, 'mayAlsoBe' => [], 'body' => null, 'bodyUnknown' => false];
    }

    /**
     * A chain that ran: what it settles replaces what came before, as an outer link of one chain does.
     *
     * @param  Restated  $state
     * @param  PeeledChain  $chain
     * @return Restated
     */
    private static function over(array $state, array $chain): array
    {
        if ($chain['status'] !== null || $chain['statusUnknown']) {
            $state = ['status' => $chain['status'], 'statusUnknown' => $chain['statusUnknown'], 'mayAlsoBe' => []] + $state;
        }
        if ($chain['contentType'] !== null || $chain['contentTypeUnknown']) {
            $state = ['contentType' => $chain['contentType'], 'contentTypeUnknown' => $chain['contentTypeUnknown']] + $state;
        }
        if ($chain['body'] !== null || $chain['bodyUnknown']) {
            $state = ['body' => $chain['body'], 'bodyUnknown' => $chain['bodyUnknown']] + $state;
        }

        return $state;
    }

    /**
     * A chain that may not have run: its status is one more the response can be sent with, and a media type
     * or a body set on one path and not another is no one media type or body.
     *
     * @param  Restated  $state
     * @param  PeeledChain  $chain
     * @return Restated
     */
    private static function besides(array $state, array $chain): array
    {
        if ($chain['status'] !== null) {
            $state['mayAlsoBe'][] = $chain['status'];
        } elseif ($chain['statusUnknown']) {
            $state = ['status' => null, 'statusUnknown' => true, 'mayAlsoBe' => []] + $state;
        }
        if ($chain['contentType'] !== null || $chain['contentTypeUnknown']) {
            $state = ['contentType' => null, 'contentTypeUnknown' => true] + $state;
        }
        if ($chain['body'] !== null || $chain['bodyUnknown']) {
            $state = ['body' => null, 'bodyUnknown' => true] + $state;
        }

        return $state;
    }

    /** @return Restated */
    private static function unread(): array
    {
        return ['status' => null, 'statusUnknown' => true, 'contentType' => null, 'contentTypeUnknown' => true, 'mayAlsoBe' => [], 'body' => null, 'bodyUnknown' => true];
    }

    /** Whether a chain was peeled all the way down to the local itself. */
    private static function rootOf(Node\Expr $receiver, string $name): bool
    {
        return $receiver instanceof Node\Expr\Variable && $receiver->name === $name;
    }

    /**
     * The expression statements that run on every path to offset `$at`: those written straight in each
     * block that holds it, ahead of the statement that does.
     *
     * @param  list<Node\Stmt>  $statements
     * @param  array<int, true>  $definite  by object id
     */
    private static function markDefinite(array $statements, int $at, array &$definite): void
    {
        foreach ($statements as $statement) {
            if ($statement->getEndFilePos() < $at) {
                if ($statement instanceof Node\Stmt\Expression) {
                    $definite[spl_object_id($statement)] = true;
                }

                continue;
            }
            if ($statement->getStartFilePos() > $at) {
                return;
            }

            foreach ($statement->getSubNodeNames() as $sub) {
                $value = $statement->{$sub};
                $blocks = array_values(array_filter(is_array($value) ? $value : [$value], static fn (mixed $child): bool => $child instanceof Node\Stmt));
                if (self::holds($blocks, $at)) {
                    self::markDefinite($blocks, $at, $definite);
                }
            }

            return;
        }
    }

    /** @param  list<Node\Stmt>  $statements */
    private static function holds(array $statements, int $at): bool
    {
        foreach ($statements as $statement) {
            if ($statement->getStartFilePos() <= $at && $statement->getEndFilePos() >= $at) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every restatement of `$name` written ahead of `$at`, in source order, as `[kind, node, runs on every
     * path]`. A closure, arrow function or class declared in the body is not entered.
     *
     * @param  Node|array<mixed>  $node
     * @param  array<int, true>  $definite
     * @param  list<array{string, Node, bool}>  $events
     */
    private static function walk(Node|array $node, ?Node $parent, ?Node\Stmt $statement, string $name, int $at, array $definite, array &$events): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                if ($child instanceof Node) {
                    self::walk($child, $parent, $statement, $name, $at, $definite, $events);
                }
            }

            return;
        }

        if ($node->getStartFilePos() >= $at || $node instanceof Node\Stmt\ClassLike) {
            return;
        }
        if ($node instanceof Node\FunctionLike) {
            // A body of its own, run whenever whoever holds it calls it: one that captures the object may
            // have changed it by the return, in ways this does not follow.
            if (self::captures($node, $name)) {
                $events[] = ['touched', $node, false];
            }

            return;
        }

        $statement = $node instanceof Node\Stmt ? $node : $statement;
        $event = self::event($node, $parent, $statement, $name, $definite);
        if ($event !== null) {
            $events[] = $event;
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $child = $node->{$sub};
            if ($child instanceof Node || is_array($child)) {
                self::walk($child, $node, $statement, $name, $at, $definite, $events);
            }
        }
    }

    /**
     * @param  array<int, true>  $definite
     * @return array{string, Node, bool}|null
     */
    private static function event(Node $node, ?Node $parent, ?Node\Stmt $statement, string $name, array $definite): ?array
    {
        $runs = $statement instanceof Node\Stmt\Expression && isset($definite[spl_object_id($statement)]);

        $assignment = LocalWrites::assignment($node);
        if ($assignment !== null && $assignment[0] === $name) {
            $replaces = $runs && $statement->expr === $node;

            return [$replaces ? 'replaced' : 'written', $node, $replaces];
        }
        if (in_array($name, LocalWrites::retires($node), true)) {
            return ['written', $node, false];
        }
        if (self::writesThrough($node, $name)) {
            return ['touched', $node, false];
        }

        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && ! (($parent instanceof Node\Expr\MethodCall || $parent instanceof Node\Expr\NullsafeMethodCall) && $parent->var === $node)
            && self::chainRoot($node, $name)
        ) {
            // `$response->setStatusCode(202);`, or the same with what it hands back kept in another local.
            $whole = $runs && ($statement->expr === $node || ($statement->expr instanceof Node\Expr\Assign && $statement->expr->expr === $node));

            return ['chain', $node, $node instanceof Node\Expr\MethodCall && $whole];
        }

        if ($node instanceof Node\Expr\Variable && $node->name === $name && $parent instanceof Node\Arg && $parent->value === $node) {
            return ['handed', $node, false];
        }

        return null;
    }

    /** Whether a closure binds the local, or an arrow function names it. */
    private static function captures(Node\FunctionLike $function, string $name): bool
    {
        if ($function instanceof Node\Expr\Closure) {
            foreach ($function->uses as $use) {
                if ($use->var->name === $name) {
                    return true;
                }
            }

            return false;
        }

        return $function instanceof Node\Expr\ArrowFunction
            && (new NodeFinder)->findFirst($function->expr, static fn (Node $node): bool => $node instanceof Node\Expr\Variable && $node->name === $name) !== null;
    }

    /** Whether a chain of calls starts on the local itself. */
    private static function chainRoot(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, string $name): bool
    {
        $root = $call->var;
        while ($root instanceof Node\Expr\MethodCall || $root instanceof Node\Expr\NullsafeMethodCall) {
            $root = $root->var;
        }

        return $root instanceof Node\Expr\Variable && $root->name === $name;
    }

    /** `$response->headers = …`, `unset($response->original)`: a write through the object, not of the local. */
    private static function writesThrough(Node $node, string $name): bool
    {
        $targets = match (true) {
            $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
            $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PostInc,
            $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostDec => [$node->var],
            $node instanceof Node\Stmt\Unset_ => $node->vars,
            default => [],
        };

        // The local's own writes are asked first ({@see LocalWrites}), so a target rooted at it is one through it.
        foreach ($targets as $target) {
            while ($target instanceof Node\Expr\PropertyFetch || $target instanceof Node\Expr\NullsafePropertyFetch || $target instanceof Node\Expr\ArrayDimFetch) {
                $target = $target->var;
            }
            if ($target instanceof Node\Expr\Variable && $target->name === $name) {
                return true;
            }
        }

        return false;
    }
}
