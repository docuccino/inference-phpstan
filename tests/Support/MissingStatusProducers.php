<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Core\TypeGrammar\PhpDocParserStack;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/**
 * Which methods of one class DECLARE that they may answer with a missing status — the derivation behind
 * `UnreadStatusTest`'s union check, held apart from it so the reading can be executed against a producer
 * written in each spelling rather than only against the package as it stands today.
 *
 * The reading is of the TYPE, never of a spelling of it. A declared type is taken through the same
 * phpdoc grammar the product uses, `@phpstan-type` aliases are resolved to what they name, and the tree is
 * walked: a slot that can be an `int` and can also be absent — `?int`, `int|null`, an optional `status?:
 * int`, the same one level down inside a `list<Alias>` — is a missing status however it was written. A
 * regex over one spelling answered "not a producer" for a method whose return admits one, which is the one
 * answer that lets a new producer skip its row.
 *
 * Key names are deliberately not read. A shape slot renamed is the same invisibility one spelling along,
 * and the cost of ignoring the name is a method that owes a row it can answer "not a status" in — loud,
 * where reading the name is silent.
 */
final class MissingStatusProducers
{
    /** Enough hops to read an alias that names an alias, or a shape inside a shape, without hanging on one that names itself. */
    private const DEPTH = 10;

    /** Every `@return` family, all of them read: any one may carry the answer, and this asks whether ANY admits a missing status. */
    private const RETURN_TAGS = ['@phpstan-return', '@psalm-return', '@return'];

    /**
     * The method names of `$target` whose declared type admits a missing status, sorted.
     *
     * Aliases are collected from EVERY source given, because a shape is declared once where it belongs and
     * imported where it is answered.
     *
     * @param  array<string, string>  $sources  file → its PHP source, one of which is `$target`
     * @return list<string>
     */
    public static function in(array $sources, string $target): array
    {
        $stack = new PhpDocParserStack;
        $aliases = self::aliases($sources);

        $found = [];
        foreach ((new NodeFinder)->find(self::parse($sources[$target] ?? ''), static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod) as $method) {
            /** @var Node\Stmt\ClassMethod $method */
            foreach (self::declared($method, $stack) as $type) {
                if (self::admitsMissingStatus($type, $aliases)) {
                    $found[] = $method->name->toString();
                    break;
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Every `@phpstan-type` alias declared anywhere in the given sources, as name → the type it names.
     *
     * @param  array<string, string>  $sources
     * @return array<string, TypeNode>
     */
    public static function aliases(array $sources): array
    {
        $stack = new PhpDocParserStack;
        $finder = new NodeFinder;

        $aliases = [];
        foreach ($sources as $source) {
            foreach ($finder->find(self::parse($source), static fn (Node $node): bool => $node->getDocComment() !== null) as $node) {
                $doc = $stack->parseDocBlock((string) $node->getDocComment()?->getText());
                foreach ($doc?->getTypeAliasTagValues() ?? [] as $alias) {
                    $aliases[$alias->alias] = $alias->type;
                }
            }
        }

        return $aliases;
    }

    /**
     * The types one method declares its answer with: its native return type, and what its docblock says.
     *
     * @return list<TypeNode>
     */
    private static function declared(Node\Stmt\ClassMethod $method, PhpDocParserStack $stack): array
    {
        $types = [];

        $native = self::nativeType($method->returnType);
        if ($native !== null) {
            $parsed = $stack->parseType($native);
            if ($parsed !== null) {
                $types[] = $parsed;
            }
        }

        $doc = $stack->parseDocBlock((string) $method->getDocComment()?->getText());
        foreach (self::RETURN_TAGS as $tag) {
            foreach ($doc?->getReturnTagValues($tag) ?? [] as $return) {
                $types[] = $return->type;
            }
        }

        return $types;
    }

    /** A native type as the phpdoc grammar spells it, or null where there is none to read. */
    private static function nativeType(?Node $type): ?string
    {
        if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
            return $type->toString();
        }

        if ($type instanceof Node\NullableType) {
            $inner = self::nativeType($type->type);

            return $inner === null ? null : '?'.$inner;
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $parts = [];
            foreach ($type->types as $part) {
                $one = self::nativeType($part);
                if ($one === null) {
                    return null;
                }

                $parts[] = $one;
            }

            return implode($type instanceof Node\UnionType ? '|' : '&', $parts);
        }

        return null;
    }

    /**
     * Whether this type, or any slot inside it, can be an `int` and can also be absent.
     *
     * @param  array<string, TypeNode>  $aliases
     */
    private static function admitsMissingStatus(TypeNode $type, array $aliases, int $depth = 0): bool
    {
        if ($depth > self::DEPTH) {
            return false;
        }

        $parts = self::parts($type, $aliases);

        // A method that can only answer "missing" — `unread(): null` — is the whole answer, so it is read
        // at the top and nowhere else: a `?string` deeper in a shape is not a status.
        if ($depth === 0 && count($parts) === 1 && self::names($parts, 'null')) {
            return true;
        }

        if (self::names($parts, 'int') && self::names($parts, 'null')) {
            return true;
        }

        foreach ($parts as $part) {
            if ($part instanceof ArrayShapeNode) {
                foreach ($part->items as $item) {
                    // An optional slot is a missing status by absence rather than by null.
                    if ($item->optional && self::names(self::parts($item->valueType, $aliases), 'int')) {
                        return true;
                    }

                    if (self::admitsMissingStatus($item->valueType, $aliases, $depth + 1)) {
                        return true;
                    }
                }
            }

            if ($part instanceof GenericTypeNode) {
                foreach ($part->genericTypes as $generic) {
                    if (self::admitsMissingStatus($generic, $aliases, $depth + 1)) {
                        return true;
                    }
                }
            }

            if ($part instanceof ArrayTypeNode && self::admitsMissingStatus($part->type, $aliases, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a type offers as one flat set: a union flattened, a `?T` read as `T` beside `null`, and an alias
     * replaced by what it names.
     *
     * @param  array<string, TypeNode>  $aliases
     * @return list<TypeNode>
     */
    private static function parts(TypeNode $type, array $aliases, int $depth = 0): array
    {
        if ($type instanceof UnionTypeNode) {
            $parts = [];
            foreach ($type->types as $member) {
                $parts = [...$parts, ...self::parts($member, $aliases, $depth)];
            }

            return $parts;
        }

        if ($type instanceof NullableTypeNode) {
            return [new IdentifierTypeNode('null'), ...self::parts($type->type, $aliases, $depth)];
        }

        if ($type instanceof IdentifierTypeNode && isset($aliases[$type->name]) && $depth < self::DEPTH) {
            return self::parts($aliases[$type->name], $aliases, $depth + 1);
        }

        return [$type];
    }

    /**
     * @param  list<TypeNode>  $parts
     */
    private static function names(array $parts, string $name): bool
    {
        foreach ($parts as $part) {
            if ($part instanceof IdentifierTypeNode && strtolower($part->name) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, Node\Stmt>
     */
    private static function parse(string $source): array
    {
        return (new ParserFactory)->createForHostVersion()->parse($source) ?? [];
    }
}
