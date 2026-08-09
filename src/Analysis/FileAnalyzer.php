<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;

/**
 * Parses a file once and harvests its virtual `MethodReturnStatementsNode`s,
 * keyed by method name — the structured-harvest node that pairs every `return`
 * with its flow-refined scope and carries the method's throw points (design §2).
 * Memoised per file so descent re-uses a single rich parse; the adapter's
 * priming guarantees bodies survive.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
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
            // The documented structured-harvest node (design §2, Spike A): watching
            // for it is the sanctioned way to pair returns with flow-refined scope.
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof MethodReturnStatementsNode) {
                $collected[$node->getMethodName()] = $node;
            }
        });

        return $this->cache[$normalised] = $collected;
    }

    /**
     * Harvest the file's closure return-statement nodes keyed by the closure's start line — the
     * locator for an exception-handler render callback (design §6 inferred-handler tier), whose
     * file+line come from `ReflectionFunction`. Same structured-harvest node family as
     * {@see analyze()}, so returns pair with their flow-refined scope identically.
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
     * Harvest the file's `$var = [ ... ]` array-literal assignments, keyed by the containing method name
     * then by variable name (FIRST assignment wins — the initialiser). It lets the refiner recover the
     * member→parameter provenance of a response body that is BUILT UP in a local variable before being
     * handed to `new JsonResponse($body, …)` (the idiomatic shape: a `$body` array literal followed by
     * conditional `$body[...] = …` appends), not only the inline `new JsonResponse([...], …)` case.
     * Conditional appends (assignments to an array-dim, not the bare variable) are ignored here — the
     * payload SHAPE still comes from PHPStan's inferred type of the variable at the return.
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

            // First assignment wins: the initialiser carries the member→parameter provenance; a later
            // reassignment in the same method does not overwrite it.
            $collected[$method][$node->var->name] ??= $node->expr;
        });

        return $this->arrayAssignmentCache[$normalised] = $collected;
    }
}
