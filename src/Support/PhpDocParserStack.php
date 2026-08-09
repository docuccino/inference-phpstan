<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

/**
 * The single phpstan/phpdoc-parser stack — the one type/doc grammar used everywhere (design §6).
 * Owns the lexer + parser construction and the two tolerant parse entry points (a raw docblock to a
 * {@see PhpDocNode}; a type string to a {@see TypeNode}), so the adapter's docblock reader, the
 * engine's docblock reader and the attribute type-string parser share one wiring instead of each
 * re-rolling it. Lives here (not in the framework-agnostic `docuccino/core`) because core stays
 * free of the phpdoc-parser dependency.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class PhpDocParserStack
{
    private readonly Lexer $lexer;

    private readonly PhpDocParser $phpDocParser;

    private readonly TypeParser $typeParser;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $this->typeParser = new TypeParser($config, $constExprParser);
        $this->phpDocParser = new PhpDocParser($config, $this->typeParser, $constExprParser);
    }

    /** Parse a raw docblock, or null when it is empty or unparseable. */
    public function parseDocBlock(?string $docComment): ?PhpDocNode
    {
        if ($docComment === null || $docComment === '') {
            return null;
        }

        try {
            return $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($docComment)));
        } catch (Throwable) {
            return null;
        }
    }

    /** Parse a phpdoc type string, or null when it is empty or unparseable. */
    public function parseType(string $type): ?TypeNode
    {
        $type = trim($type);
        if ($type === '') {
            return null;
        }

        try {
            return $this->typeParser->parse(new TokenIterator($this->lexer->tokenize($type)));
        } catch (Throwable) {
            return null;
        }
    }
}
