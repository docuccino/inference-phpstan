<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use PhpParser\Node;

/**
 * The engine's one convention for ordering nodes by where they appear in a file: the byte offset of the
 * node's OWN syntax, and `PHP_INT_MAX` for a node php-parser gave no position.
 *
 * Both orderings that use it feed a FIRST-WINS selection — the return site a refinement picks, and the
 * callee a trace descends into — so an unpositioned node has to sort LAST. Sorting it first would let a
 * site nothing can locate displace one that can, which is a confident answer built on the least
 * evidence available.
 *
 * A CALL is the one node whose own syntax does not start where the node does: php-parser gives
 * `$a->b()->c()` and its inner `$a->b()` the same start offset, the receiver's, so every link in one
 * chain answers the same position and a sort over them TIES. A tie is not a neutral outcome here — it
 * hands the order back to whatever handed the nodes over, which for the trace is PHPStan's node-callback
 * order for a chained expression, the very order the sort exists to neutralise. So a call is positioned
 * at its method name, which is unique per link and runs left-to-right along the chain: the order the
 * links are written, which is also the order they evaluate.
 *
 * @internal
 */
final class SourceOrder
{
    /** A node's own byte offset, or `PHP_INT_MAX` when it has none. */
    public static function of(Node $node): int
    {
        $pos = self::own($node)->getStartFilePos();

        return $pos >= 0 ? $pos : PHP_INT_MAX;
    }

    /**
     * The node whose offset positions this one — itself, except for a call, which reports its receiver's
     * offset and so has to be positioned by its name.
     */
    private static function own(Node $node): Node
    {
        return match (true) {
            $node instanceof Node\Expr\MethodCall,
            $node instanceof Node\Expr\NullsafeMethodCall,
            $node instanceof Node\Expr\StaticCall => $node->name,
            default => $node,
        };
    }
}
