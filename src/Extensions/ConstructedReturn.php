<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use Docuccino\Inference\PhpStan\Support\MethodDeclaration;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

/**
 * The one class a method's body constructs on every path, where each `return` is a `new` of it by name.
 * Read from source, because the signature — or a docblock an untyped override inherits — can name less
 * than the body builds; any other return, or two classes, and there is no answer.
 *
 * @internal
 */
final class ConstructedReturn
{
    /** @var array<string, list<Node>> file → its name-resolved statements */
    private array $files = [];

    public function of(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if (! is_string($file)) {
            return null;
        }

        $body = MethodDeclaration::in($this->statements($file), $method);
        if ($body === null || $body->stmts === null) {
            return null;
        }

        $built = [];
        foreach (self::returns($body->stmts) as $return) {
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

    /** @return list<Node> */
    private function statements(string $file): array
    {
        if (isset($this->files[$file])) {
            return $this->files[$file];
        }

        try {
            $code = is_file($file) ? file_get_contents($file) : false;
            $statements = $code === false ? null : (new ParserFactory)->createForHostVersion()->parse($code);
        } catch (Throwable) {
            $statements = null;
        }

        return $this->files[$file] = $statements === null ? [] : array_values((new NodeTraverser(new NameResolver))->traverse($statements));
    }
}
