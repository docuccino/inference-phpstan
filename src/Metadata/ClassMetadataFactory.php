<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\IntersectionT;
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
                type: $this->typeStrings->parse($tag['type'], $this->importsOf($reflection)),
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
     * The declared type, refined by a docblock in the two places reflection cannot speak for itself: where
     * the reflected type is vague (bare `array`, `mixed`, undeclared) a docblock type REPLACES it, and only
     * if itself precise, so a native `string` is never second-guessed; where it is precise but generic-blind
     * (a bare `ClassT`) a docblock may only PARAMETERISE it, via {@see self::parameterise()}.
     */
    private function propertyType(ReflectionProperty $property, ?string $docComment): DType
    {
        $native = $this->typeMapper->map($property->getType());
        $precise = self::precise($native);
        if ($precise && ! self::parameterisable($native)) {
            return $native;
        }

        $declaring = $property->getDeclaringClass();
        foreach ($this->writtenTypes($property, $docComment) as $written) {
            $parsed = $this->typeStrings->parse($written, $this->importsOf($declaring));
            $refined = $precise
                ? self::parameterise($native, $parsed)
                : (self::precise($parsed) ? $parsed : null);

            if ($refined !== null) {
                return $refined;
            }
        }

        return $native;
    }

    /**
     * The docblock types that may speak for a property, most authoritative first. A promoted constructor
     * property may be documented in the constructor's `@param` or in its own `@var`, so both count and the
     * `@param` wins; a plain property has only its `@var`.
     *
     * @return list<string>
     */
    private function writtenTypes(ReflectionProperty $property, ?string $docComment): array
    {
        $written = [];

        if ($property->isPromoted()) {
            $param = $this->constructorParams($property->getDeclaringClass())[$property->getName()]['type'] ?? null;
            if ($param !== null) {
                $written[] = $param;
            }
        }

        $var = $this->docBlocks->varType($docComment);
        if ($var !== null) {
            $written[] = $var;
        }

        return $written;
    }

    /**
     * A docblock parameterising a precise native type: for every bare `ClassT` the reflected type carries, the
     * type arguments the docblock states FOR THAT SAME CLASS are grafted on; null when it adds nothing.
     * One-directional on purpose — a docblock can supply the generics reflection has no syntax for and nothing
     * else, so it can neither swap the class nor add a nullability the declaration doesn't have.
     */
    private static function parameterise(DType $native, DType $written): ?DType
    {
        if ($native instanceof UnionT) {
            $members = [];
            $refined = false;
            foreach ($native->members as $member) {
                $parameterised = self::parameterise($member, $written);
                $refined = $refined || $parameterised !== null;
                $members[] = $parameterised ?? $member;
            }

            return $refined ? UnionT::of($members) : null;
        }

        if (! $native instanceof ClassT || $native->typeArgs !== []) {
            return null;
        }

        $args = self::typeArgsFor($written, $native->fqcn);

        return $args === null ? null : new ClassT($native->fqcn, $args);
    }

    /**
     * The type arguments a written type states for `$fqcn`, looking through a union or intersection it may
     * be wrapped in (`DataCollection<int, Factor>|null`), or null if it states none.
     *
     * @return ?list<DType>
     */
    private static function typeArgsFor(DType $written, string $fqcn): ?array
    {
        if ($written instanceof UnionT || $written instanceof IntersectionT) {
            foreach ($written->members as $member) {
                $args = self::typeArgsFor($member, $fqcn);
                if ($args !== null) {
                    return $args;
                }
            }

            return null;
        }

        return $written instanceof ClassT && $written->fqcn === $fqcn && $written->typeArgs !== []
            ? $written->typeArgs
            : null;
    }

    /** Whether a type has a bare, generic-less class anywhere in it for a docblock to parameterise. */
    private static function parameterisable(DType $type): bool
    {
        if ($type instanceof UnionT) {
            foreach ($type->members as $member) {
                if (self::parameterisable($member)) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof ClassT && $type->typeArgs === [];
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
