<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Node\ReturnStatementsNode;

/**
 * One analysable callable body — a method, a closure or an arrow function — as the returns it can answer
 * with, each paired with its flow-refined scope, plus the nodes it is written in and its parameter names.
 * An arrow function has no `return`: its one implicit return is its body expression, in the scope inside it.
 *
 * @phpstan-type BodyReturn array{expr: Node\Expr|null, scope: Scope, at: Node}
 *
 * @internal
 */
final readonly class CallableBody
{
    /**
     * @param  list<BodyReturn>  $returns  `at` positions the return: its statement, or an arrow's body
     * @param  list<Node>  $nodes
     * @param  list<string>  $parameters
     */
    public function __construct(
        public array $returns,
        public array $nodes,
        public array $parameters,
    ) {}

    public static function ofMethod(MethodReturnStatementsNode $node): self
    {
        $parameters = [];
        foreach ($node->getMethodReflection()->getVariants()[0]->getParameters() as $parameter) {
            $parameters[] = $parameter->getName();
        }

        return new self(self::returnsOf($node), array_values($node->getStatements()), $parameters);
    }

    public static function ofClosure(ClosureReturnStatementsNode $node): self
    {
        $closure = $node->getClosureExpr();

        return new self(self::returnsOf($node), array_values($closure->stmts), self::names($closure->params));
    }

    public static function ofArrow(Node\Expr\ArrowFunction $arrow, Scope $scope): self
    {
        return new self([['expr' => $arrow->expr, 'scope' => $scope, 'at' => $arrow->expr]], [$arrow->expr], self::names($arrow->params));
    }

    /**
     * @return list<BodyReturn>
     */
    private static function returnsOf(ReturnStatementsNode $node): array
    {
        $returns = [];
        foreach ($node->getReturnStatements() as $statement) {
            $return = $statement->getReturnNode();
            $returns[] = ['expr' => $return->expr, 'scope' => $statement->getScope(), 'at' => $return];
        }

        return $returns;
    }

    /**
     * @param  array<Node\Param>  $params
     * @return list<string>
     */
    private static function names(array $params): array
    {
        $names = [];
        foreach ($params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }
}
