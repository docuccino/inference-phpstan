<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * Records what the engine's closure-by-line trace hands a visitor: one entry per return expression, with
 * the node kind and the type the live scope gives it. That trace is how a closure route's action is
 * walked, and an arrow function's scope is a lazy fiber scope that can't type anything once the pass has
 * ended — so typing each expression on the spot is the half worth proving on the real engine.
 */
final class ClosureReturnProbe implements TraceVisitor
{
    /** @var list<array{node: string, type: string}> */
    public array $returns = [];

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        $this->returns[] = [
            'node' => $node->getType(),
            'type' => $node instanceof Node\Expr ? $scope->typeOf($node)->canonicalKey() : '',
        ];

        return false;
    }
}
