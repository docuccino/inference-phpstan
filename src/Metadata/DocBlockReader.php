<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Inference\PhpStan\Support\PhpDocParserStack;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;

/**
 * Extracts prose + `@example` from a raw docblock using the shared {@see PhpDocParserStack} —
 * one type grammar everywhere (design). Framework-agnostic; touches no PHPStan analysis internals.
 *
 * The one docblock reader in the package: it also splits the leading prose into an OAS `summary`
 * (first paragraph) and `description` (the remainder) via {@see read()} — folded in from the
 * adapter's former `Docuccino\Laravel\Docblock\DocblockReader` once both landed in this package
 * sharing the identical parser stack, so the prose-extraction loop is written once.
 */
final class DocBlockReader
{
    public function __construct(
        private readonly PhpDocParserStack $stack = new PhpDocParserStack,
    ) {}

    /** The leading prose (summary + description), or null when absent. */
    public function summary(?string $docComment): ?string
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return null;
        }

        foreach ($node->children as $child) {
            if ($child instanceof PhpDocTextNode) {
                $text = trim($child->text);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * The `@property` / `@property-read` tags a class docblock declares (the ide-helper model-column
     * convention: `@property int $id`), as an ordered `name => {type, description}` map. Both write and
     * read forms are enumerated — a serialized attribute is readable — with `@property` before
     * `@property-read`; a duplicate name keeps its first (more-authoritative) declaration. `$` is
     * stripped from the property name; an empty description becomes null.
     *
     * @return array<string, array{type: string, description: ?string}>
     */
    public function properties(?string $docComment): array
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return [];
        }

        $out = [];
        foreach ([...$node->getPropertyTagValues(), ...$node->getPropertyReadTagValues()] as $tag) {
            $name = ltrim($tag->propertyName, '$');
            if ($name === '' || isset($out[$name])) {
                continue;
            }

            $description = trim($tag->description);
            $out[$name] = [
                'type' => (string) $tag->type,
                'description' => $description === '' ? null : $description,
            ];
        }

        return $out;
    }

    /** The first `@example` value, or null. */
    public function example(?string $docComment): ?string
    {
        $node = $this->stack->parseDocBlock($docComment);
        if ($node === null) {
            return null;
        }

        foreach ($node->getTagsByName('@example') as $tag) {
            $value = trim((string) $tag->value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Split the leading prose into an OAS `summary` (first paragraph) and `description` (the
     * remainder). Either is null when absent.
     *
     * @return array{summary: ?string, description: ?string}
     */
    public function read(?string $docComment): array
    {
        // Reuse summary()'s first-non-empty-prose scan rather than re-rolling it (the prose-extraction
        // loop is written once).
        $prose = $this->summary($docComment);
        if ($prose === null) {
            return ['summary' => null, 'description' => null];
        }

        $parts = preg_split('/\R{2,}/', $prose, 2);
        $summary = trim($parts[0] ?? $prose);
        $description = isset($parts[1]) ? trim($parts[1]) : null;

        return [
            'summary' => $summary === '' ? null : $summary,
            'description' => ($description === null || $description === '') ? null : $description,
        ];
    }
}
