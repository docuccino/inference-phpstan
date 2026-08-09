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
 * The one phpstan/phpdoc-parser stack, so there's a single type/doc grammar everywhere: lexer and parser
 * construction plus two tolerant entry points (raw docblock → {@see PhpDocNode}, type string →
 * {@see TypeNode}). Lives here rather than in `docuccino/core` to keep core free of the phpdoc-parser
 * dependency.
 *
 * @internal
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
