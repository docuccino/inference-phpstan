<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use Docuccino\Inference\PhpStan\Support\ParsedFiles;
use PhpParser\Node;
use ReflectionMethod;

/**
 * The one class a method's body constructs on every path, where each `return` is a `new` of it by name.
 * Read from source, because the signature — or a docblock an untyped override inherits — can name less
 * than the body builds; any other return, or two classes, and there is no answer.
 *
 * @internal
 */
final class ConstructedReturn
{
    public function __construct(private readonly ParsedFiles $files = new ParsedFiles) {}

    public function of(ReflectionMethod $method): ?string
    {
        $body = $this->files->body($method);
        if ($body === null) {
            return null;
        }

        $built = [];
        foreach (self::returns($body) as $return) {
            $class = $return->expr instanceof Node\Expr\New_ ? $return->expr->class : null;
            if (! $class instanceof Node\Name\FullyQualified) {
                return null;
            }
            $built[$class->toString()] = true;
        }

        return count($built) === 1 ? array_key_first($built) : null;
    }

    /**
     * Every `return` of the body itself — not of a closure, function or class declared inside it.
     *
     * @param  array<array-key, mixed>  $nodes
     * @return list<Node\Stmt\Return_>
     */
    private static function returns(array $nodes): array
    {
        $found = [];
        foreach (self::nodes($nodes) as $node) {
            if ($node instanceof Node\Stmt\Return_) {
                $found[] = $node;

                continue;
            }
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                continue;
            }
            foreach ($node->getSubNodeNames() as $subNode) {
                $found = [...$found, ...self::returns(self::nodes($node->{$subNode}))];
            }
        }

        return $found;
    }

    /** @return list<Node> */
    private static function nodes(mixed $value): array
    {
        if ($value instanceof Node) {
            return [$value];
        }

        return is_array($value) ? array_values(array_filter($value, static fn (mixed $node): bool => $node instanceof Node)) : [];
    }
}
