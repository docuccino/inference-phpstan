<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * A test-only Query-Builder trace visitor — a miniature of the Phase-4 QB
 * integration. Pure semantics + harvesting through {@see TypeScope}; imports
 * zero PHPStan. Proves the {@see TypeEngine::trace()}
 * boundary recovers allowedFilters/Sorts literals through a 2-deep chain and
 * detects a custom pagination terminal.
 *
 * @phpstan-type Terminal array{terminal: string, perPage: int|null}
 */
final class QueryBuilderProbe implements TraceVisitor
{
    private const QUERY_BUILDER = 'Spatie\\QueryBuilder\\QueryBuilder';

    private const TERMINALS = ['paginate', 'simplePaginate', 'cursorPaginate', 'paginateList'];

    /** @var list<string> */
    public array $allowedFilters = [];

    /** @var list<string> */
    public array $allowedSorts = [];

    /** @var list<string> */
    public array $defaultSort = [];

    /** @var list<Terminal> */
    public array $terminals = [];

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Node\Expr\MethodCall) {
            $this->visitMethodCall($node, $scope);
        }

        // Descend into any app-code call (the engine declines vendor/magic).
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    public function paginates(): bool
    {
        return $this->terminals !== [];
    }

    /**
     * The outermost terminal — the one at the call site. The Tracer walks the
     * entry method fully before descending, so the first terminal recorded is the
     * shallowest (here `paginateList(25)`, not the vendor `paginate` it forwards
     * to one hop deeper). Per design §4 this outermost call supplies per-page.
     *
     * @return Terminal|null
     */
    public function outermostTerminal(): ?array
    {
        return $this->terminals[0] ?? null;
    }

    /**
     * Per-page folds from the OUTERMOST terminal's argument (the call site),
     * NOT merely the first terminal that happens to carry a constant.
     */
    public function recoveredPerPage(): ?int
    {
        return $this->outermostTerminal()['perPage'] ?? null;
    }

    private function visitMethodCall(Node\Expr\MethodCall $node, TypeScope $scope): void
    {
        if (! $node->name instanceof Node\Identifier || ! $this->receiverIsBuilder($node->var, $scope)) {
            return;
        }
        $name = $node->name->toString();

        if ($name === 'allowedFilters') {
            $this->harvest($node, $scope, $this->allowedFilters);
        } elseif ($name === 'allowedSorts') {
            $this->harvest($node, $scope, $this->allowedSorts);
        } elseif ($name === 'defaultSort' || $name === 'defaultSorts') {
            $this->harvest($node, $scope, $this->defaultSort);
        }

        if (in_array($name, self::TERMINALS, true)) {
            $perPage = null;
            $args = $node->getArgs();
            if (isset($args[0])) {
                $value = $scope->constantValueOf($args[0]->value);
                if ($value !== null && $value->isScalar() && is_int($value->scalar)) {
                    $perPage = $value->scalar;
                }
            }
            $this->terminals[] = ['terminal' => $name, 'perPage' => $perPage];
        }
    }

    /**
     * @param  list<string>  $bucket
     */
    private function harvest(Node\Expr\MethodCall $node, TypeScope $scope, array &$bucket): void
    {
        foreach ($node->getArgs() as $arg) {
            $value = $arg->value;
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    if ($item instanceof Node\ArrayItem) {
                        $bucket[] = ($scope->constantValueOf($item->value) ?? ConstValue::unknown('non-constant'))->render();
                    }
                }

                continue;
            }
            $bucket[] = ($scope->constantValueOf($value) ?? ConstValue::unknown('non-constant'))->render();
        }
    }

    private function receiverIsBuilder(Node\Expr $receiver, TypeScope $scope): bool
    {
        $type = $scope->typeOf($receiver);

        return $type instanceof ClassT && is_a($type->fqcn, self::QUERY_BUILDER, true);
    }
}
