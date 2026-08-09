<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Inference\PhpStan\Types\TypeStringParser;
use ReflectionClass;
use ReflectionProperty;

/**
 * Builds {@see ClassMetadata} from native reflection + docblocks (design §4,
 * "Data/Resource/Model shapes, lazy + memoised"). Property types come from
 * native reflection (no analysis scope needed); prose + `@example` come from
 * {@see DocBlockReader}. Class-level `@property`/`@property-read` docblock tags are
 * enumerated as additional properties (the ide-helper convention that gives an
 * Eloquent model's magic attributes — which declare no PHP property — a typed,
 * high-confidence column universe), typed through the shared {@see TypeStringParser}
 * grammar. Native public properties win over a same-named docblock tag. Memoised per
 * class per run; always total (an unresolvable class yields an empty, well-formed metadata).
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class ClassMetadataFactory
{
    /** @var array<string, ClassMetadata> */
    private array $cache = [];

    public function __construct(
        private readonly DocBlockReader $docBlocks = new DocBlockReader,
        private readonly NativeTypeMapper $typeMapper = new NativeTypeMapper,
        private readonly TypeStringParser $typeStrings = new TypeStringParser,
    ) {}

    public function forClass(ClassRef $class): ClassMetadata
    {
        if (isset($this->cache[$class->fqcn])) {
            return $this->cache[$class->fqcn];
        }

        return $this->cache[$class->fqcn] = $this->build($class->fqcn);
    }

    private function build(string $fqcn): ClassMetadata
    {
        if (! class_exists($fqcn)) {
            return new ClassMetadata($fqcn);
        }

        $reflection = new ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        $location = $file !== false ? new SourceLocation($file) : null;
        $properties = [];
        $seen = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $docComment = $property->getDocComment();
            $docComment = $docComment === false ? null : $docComment;
            $properties[] = new PropertyMetadata(
                name: $property->getName(),
                type: $this->typeMapper->map($property->getType()),
                summary: $this->docBlocks->summary($docComment),
                example: $this->docBlocks->example($docComment),
                location: $location,
            );
            $seen[$property->getName()] = true;
        }

        $classDoc = $reflection->getDocComment();
        $classDocComment = $classDoc === false ? null : $classDoc;

        // `@property`/`@property-read` docblock columns (ide-helper convention): the authoritative,
        // typed source for a model's magic attributes, which declare no PHP property. A native public
        // property of the same name already covers it (more precise), so it is not overwritten.
        foreach ($this->docBlocks->properties($classDocComment) as $name => $tag) {
            if (isset($seen[$name])) {
                continue;
            }
            $properties[] = new PropertyMetadata(
                name: $name,
                type: $this->typeStrings->parse($tag['type']),
                summary: $tag['description'],
                location: $location,
            );
            $seen[$name] = true;
        }

        return new ClassMetadata(
            fqcn: $fqcn,
            properties: $properties,
            summary: $this->docBlocks->summary($classDocComment),
            dependencyFiles: $file !== false ? [$file] : [],
        );
    }
}
