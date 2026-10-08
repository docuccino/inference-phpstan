<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Docuccino\Core\Inference\MethodDeclaration;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

/**
 * Source files parsed and name-resolved once each, for the readers that answer from what a method's body
 * says rather than from the analyser. A file that cannot be read or parsed is empty: no statements, no answer.
 *
 * @internal
 */
final class ParsedFiles
{
    /** @var array<string, list<Node\Stmt>> file → its name-resolved statements */
    private array $files = [];

    /** @return list<Node\Stmt> */
    public function statements(string $file): array
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

        if ($statements === null) {
            return $this->files[$file] = [];
        }

        return $this->files[$file] = array_values(array_filter(
            (new NodeTraverser(new NameResolver))->traverse($statements),
            static fn (Node $node): bool => $node instanceof Node\Stmt,
        ));
    }

    /**
     * The statements of the body a method is written with, read from the file that writes it (a trait's, for
     * a trait's method); null where it has none or it cannot be found.
     *
     * @return array<Node\Stmt>|null
     */
    public function body(ReflectionMethod $method): ?array
    {
        $file = $method->getFileName();

        return is_string($file) ? MethodDeclaration::in($this->statements($file), $method)?->stmts : null;
    }
}
