<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;

/**
 * Parses a file once and harvests its virtual `MethodReturnStatementsNode`s by method name — the node that
 * pairs every `return` with its flow-refined scope and carries the method's throw points. Memoised per file
 * so descent reuses one rich parse; the adapter's priming is what keeps the bodies from being stripped.
 *
 * @internal
 */
final class FileAnalyzer
{
    /** @var array<string, array<string, MethodReturnStatementsNode>> */
    private array $cache = [];

    /** @var array<string, array<int, ClosureReturnStatementsNode>> */
    private array $closureCache = [];

    /** @var array<string, array<string, array<string, Node\Expr\Array_>>> file → method → varName → first array-literal assigned */
    private array $arrayAssignmentCache = [];

    public function __construct(private readonly RuntimeAdapter $adapter) {}

    /**
     * Every node this class hands out is consumed after its walk finished, so the scopes hanging off them
     * must be stabilised before they are queried — see {@see RuntimeAdapter::stableScope()}.
     */
    public function stableScope(Scope $scope): Scope
    {
        return $this->adapter->stableScope($scope);
    }

    /**
     * @return array<string, MethodReturnStatementsNode>
     */
    public function analyze(string $file): array
    {
        $normalised = $this->adapter->normalize($file);
        if (isset($this->cache[$normalised])) {
            return $this->cache[$normalised];
        }

        $collected = [];
        $this->adapter->processFile($file, static function (Node $node, Scope $scope) use (&$collected): void {
            // Watching for this virtual node is the sanctioned way to pair returns with refined scope.
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof MethodReturnStatementsNode) {
                $collected[$node->getMethodName()] = $node;
            }
        });

        return $this->cache[$normalised] = $collected;
    }

    /**
     * Keyed by start line — how an exception-handler render callback is located, since `ReflectionFunction`
     * gives us file+line and nothing else.
     *
     * @return array<int, ClosureReturnStatementsNode>
     */
    public function closures(string $file): array
    {
        $normalised = $this->adapter->normalize($file);
        if (isset($this->closureCache[$normalised])) {
            return $this->closureCache[$normalised];
        }

        $collected = [];
        $this->adapter->processFile($file, static function (Node $node, Scope $scope) use (&$collected): void {
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof ClosureReturnStatementsNode) {
                $collected[$node->getClosureExpr()->getStartLine()] = $node;
            }
        });

        return $this->closureCache[$normalised] = $collected;
    }

    /**
     * The file's `$var = [ ... ]` assignments by method then variable name, first assignment winning. Lets
     * the refiner recover provenance for a body built up in a local (`$body = [...]` then conditional
     * `$body[...] = …`) rather than written inline. The appends are ignored — the payload shape still comes
     * from PHPStan's inferred type of the variable at the return.
     *
     * @return array<string, array<string, Node\Expr\Array_>>
     */
    public function arrayAssignments(string $file): array
    {
        $normalised = $this->adapter->normalize($file);
        if (isset($this->arrayAssignmentCache[$normalised])) {
            return $this->arrayAssignmentCache[$normalised];
        }

        /** @var array<string, array<string, Node\Expr\Array_>> $collected */
        $collected = [];
        $this->adapter->processFile($file, static function (Node $node, Scope $scope) use (&$collected): void {
            if (! $node instanceof Node\Expr\Assign
                || ! $node->var instanceof Node\Expr\Variable
                || ! is_string($node->var->name)
                || ! $node->expr instanceof Node\Expr\Array_
            ) {
                return;
            }

            $method = $scope->getFunctionName();
            if ($method === null) {
                return;
            }

            // First assignment wins — the initialiser carries the provenance.
            $collected[$method][$node->var->name] ??= $node->expr;
        });

        return $this->arrayAssignmentCache[$normalised] = $collected;
    }
}
