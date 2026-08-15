<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use PhpParser\Node;

/**
 * The engine's one convention for ordering nodes by where they appear in a file: the byte offset, and
 * `PHP_INT_MAX` for a node php-parser gave no position.
 *
 * Both orderings that use it feed a FIRST-WINS selection — the return site a refinement picks, and the
 * callee a trace descends into — so an unpositioned node has to sort LAST. Sorting it first would let a
 * site nothing can locate displace one that can, which is a confident answer built on the least
 * evidence available.
 *
 * @internal
 */
final class SourceOrder
{
    /** A node's byte offset, or `PHP_INT_MAX` when it has none. */
    public static function of(Node $node): int
    {
        $pos = $node->getStartFilePos();

        return $pos >= 0 ? $pos : PHP_INT_MAX;
    }
}
