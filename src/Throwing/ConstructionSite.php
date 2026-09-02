<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;

/**
 * One `new` the status read folds, with the two facts about WHERE it is written that folding it needs: the
 * file, because the scope an argument folds in is the one at that line, and the class the body belongs to,
 * because that is what `self` names in it.
 *
 * Both travel with the construction rather than with the read, because a class's constructions are not all
 * written in one place: a base's `new static(…)` builds the subclass from the base's own file and class.
 *
 * @internal
 */
final class ConstructionSite
{
    public function __construct(
        public readonly Node\Expr\New_ $construction,
        public readonly string $file,
        public readonly string $declaringClass,
    ) {}
}
