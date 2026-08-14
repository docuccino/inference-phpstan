<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\ConstValue;
use PhpParser\Node;

/**
 * What a callee's single `return` folded to: the value, plus the returned expression itself so a visitor
 * can read AST the value can't carry (the closure inside `AllowedFilter::callback('q', fn …)`). The node
 * belongs to the callee's file, so it is AST-only — never typed against the calling scope.
 *
 * @internal
 */
final readonly class FoldedReturn
{
    public function __construct(
        public ConstValue $value,
        public Node\Expr $expr,
    ) {}
}
