<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\TypeGrammar\DocBlockReader;
use Docuccino\Core\TypeGrammar\ImportContext;
use Docuccino\Core\TypeGrammar\TypeStringParser;
use ReflectionClass;
use ReflectionProperty;

/**
 * Builds {@see ClassMetadata} from native reflection plus docblocks — no analysis scope needed. Class-level
 * `@property`/`@property-read` tags count as extra properties, typed through the shared
 * {@see TypeStringParser}: that ide-helper convention is what gives an Eloquent model's magic attributes
 * (which declare no PHP property at all) a typed column universe. A native public property wins over a
 * same-named tag. Memoised per class per run, and total — an unresolvable class yields empty but well-formed
 * metadata.
 *
 * @internal
 */
final class ClassMetadataFactory
{
    /** @var array<string, ClassMetadata> */
    private array $cache = [];

    /** @var array<string, ImportContext> file → its imports, parsed at most once. */
    private array $imports = [];

    /** @var array<string, array<string, array{type: string, description: ?string}>> class → its constructor tags. */
    private array $params = [];

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
                type: $this->propertyType($property, $docComment),
                summary: $this->docBlocks->summary($docComment),
                example: $this->docBlocks->example($docComment),
                location: $location,
            );
            $seen[$property->getName()] = true;
        }

        $classDoc = $reflection->getDocComment();
        $classDocComment = $classDoc === false ? null : $classDoc;

        // Docblock columns for magic attributes. A native property of the same name is more precise, so it
        // isn't overwritten.
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

    /**
     * The declared type, refined by a docblock only where reflection is vague — a bare `array`, `mixed`, no
     * declared type at all. A promoted constructor property writes its precise type in the constructor's
     * `@param`, a plain one in its own `@var`; either only wins if it is itself precise, so `@var array` never
     * displaces a native `list`-carrying declaration and a native `string` is never second-guessed.
     */
    private function propertyType(ReflectionProperty $property, ?string $docComment): DType
    {
        $native = $this->typeMapper->map($property->getType());
        if (self::precise($native)) {
            return $native;
        }

        $declaring = $property->getDeclaringClass();
        $written = $property->isPromoted()
            ? ($this->constructorParams($declaring)[$property->getName()]['type'] ?? null)
            : $this->docBlocks->varType($docComment);

        if ($written === null) {
            return $native;
        }

        $parsed = $this->typeStrings->parse($written, $this->importsOf($declaring));

        return self::precise($parsed) ? $parsed : $native;
    }

    /**
     * The declaring class's constructor `@param` tags, parsed once however many promoted properties read them.
     *
     * @param  ReflectionClass<object>  $class
     * @return array<string, array{type: string, description: ?string}>
     */
    private function constructorParams(ReflectionClass $class): array
    {
        $name = $class->getName();
        if (! isset($this->params[$name])) {
            $doc = $class->getConstructor()?->getDocComment();
            $this->params[$name] = $this->docBlocks->params($doc === false ? null : $doc);
        }

        return $this->params[$name];
    }

    /** @param  ReflectionClass<object>  $class */
    private function importsOf(ReflectionClass $class): ImportContext
    {
        // No file (an internal or eval'd class) resolves to no imports, which forFile() already does.
        $file = $class->getFileName() ?: '';

        return $this->imports[$file] ??= ImportContext::forFile($file === '' ? null : $file);
    }

    /** Whether a type says something concrete: an `UnknownT`, anywhere in a union, means it doesn't. */
    private static function precise(DType $type): bool
    {
        if ($type instanceof UnionT) {
            foreach ($type->members as $member) {
                if (! self::precise($member)) {
                    return false;
                }
            }

            return true;
        }

        return ! $type instanceof UnknownT;
    }
}
