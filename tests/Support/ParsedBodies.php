<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * A container-free {@see ClassBodies}: bodies off a plain parse, statuses off php-parser's own
 * constant evaluator with `constant()` behind it, so a class constant folds the way the analyser folds it.
 * Names are resolved as PHPStan resolves them, which is what the class-name comparisons downstream expect.
 *
 * A trait is where the two are built differently and answer alike. Parsing a trait's file keys its methods
 * under the TRAIT; the analyser's walk of that file holds no method bodies AT ALL, because PHPStan analyses
 * a trait's body in the context of each using class — measured as `keys=[]`. So neither reader answers
 * `methods(<trait file>, <using class>)`, and a factory a trait writes declines on both sides —
 * `ThrowStatusTest` carries the real-engine row that says so.
 *
 * The scope at a call is not modelled at all — a constant folds the same wherever it is asked, and a
 * VARIABLE folds nowhere here, which is what the analyser answers too for a parameter whose callers it
 * cannot see.
 *
 * @phpstan-type ParsedFile array<string, array<string, array{
 *     stmts: array<array-key, Node\Stmt>,
 *     params: array<int, Node\Param>,
 * }>>
 */
final class ParsedBodies implements ClassBodies
{
    /** @var array<string, ParsedFile> file → class → method → its statements and its parameters */
    private array $cache = [];

    public function methods(string $file, string $class): array
    {
        return array_map(
            static fn (array $method): array => $method['stmts'],
            $this->parse($file)[$class] ?? [],
        );
    }

    public function foldInt(string $file, Node\Expr $expr, Node\Expr\New_|Node\Expr\StaticCall $at): ?int
    {
        return self::constantInt($expr);
    }

    public function intDefault(string $file, string $class, string $method, int $index): ?int
    {
        $default = $this->parameters($file, $class, $method)[$index]->default ?? null;

        // Read off the declaration, never by evaluating it — the whole reason this answer is on the seam.
        return $default === null ? null : self::constantInt($default);
    }

    private static function constantInt(Node\Expr $expr): ?int
    {
        $evaluator = new ConstExprEvaluator(static function (Node\Expr $expr): mixed {
            $name = match (true) {
                $expr instanceof Node\Expr\ClassConstFetch,
                $expr instanceof Node\Expr\ConstFetch => self::constantName($expr),
                default => null,
            };

            return $name !== null && defined($name) ? constant($name) : throw new ConstExprEvaluationException;
        });

        try {
            $value = $evaluator->evaluateSilently($expr);
        } catch (ConstExprEvaluationException) {
            return null;
        }

        return is_int($value) ? $value : null;
    }

    /**
     * @return array<int, Node\Param>
     */
    private function parameters(string $file, string $class, string $method): array
    {
        return $this->parse($file)[$class][$method]['params'] ?? [];
    }

    private static function constantName(Node\Expr\ClassConstFetch|Node\Expr\ConstFetch $expr): ?string
    {
        if ($expr instanceof Node\Expr\ConstFetch) {
            return $expr->name->toString();
        }

        return $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier
            ? $expr->class->toString().'::'.$expr->name->toString()
            : null;
    }

    /**
     * @return ParsedFile
     */
    private function parse(string $file): array
    {
        if (isset($this->cache[$file])) {
            return $this->cache[$file];
        }

        $source = is_file($file) ? (string) file_get_contents($file) : '';
        $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $resolved = (new NodeTraverser(new NameResolver))->traverse($statements);

        $classes = [];
        foreach ((new NodeFinder)->findInstanceOf($resolved, Node\Stmt\ClassLike::class) as $class) {
            $name = (string) $class->namespacedName;
            foreach ($class->getMethods() as $method) {
                $classes[$name][$method->name->toString()] = [
                    'stmts' => $method->stmts ?? [],
                    'params' => array_values($method->params),
                ];
            }
        }

        return $this->cache[$file] = $classes;
    }
}
