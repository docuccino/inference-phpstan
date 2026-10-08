<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Metadata\FixedPropertyValues;
use Docuccino\Inference\PhpStan\Metadata\ReachedStatements;
use Docuccino\Inference\PhpStan\Support\ParsedFiles;
use JsonSerializable;
use PhpParser\Node;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Which members of an object body are read off its constructor's parameters, the object-payload half of
 * the member provenance an array body carries inline: a property the constructor assigns `$param->x()` (or
 * `$param` itself), or the status-text table read at one (`Response::$statusTexts[$this->status] ?? 'Error'`).
 * Accessors come back in the constructor's own parameter names, for the construction site to re-home.
 *
 * @internal
 */
final class ConstructorEchoes
{
    /** Interfaces through which a response serialises an object as something other than its public properties. */
    private const OWN_JSON = [
        JsonSerializable::class,
        'Illuminate\\Contracts\\Support\\Jsonable',
        'Illuminate\\Contracts\\Support\\Arrayable',
    ];

    /**
     * @param  Closure(string): void  $touch  records a file the answer was read out of, each time it is read
     */
    public function __construct(
        private readonly Closure $touch,
        private readonly ParsedFiles $files = new ParsedFiles,
    ) {}

    /**
     * Property → the parameter accessor it echoes, and for a table read the `??` fallback, in assignment
     * order. Empty where the class says nothing this can stand behind.
     *
     * @return array<string, array{accessor: ParamAccessor, text: bool, fallback: ?LiteralT}>
     */
    public function of(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $class = new ReflectionClass($fqcn);
        foreach (self::OWN_JSON as $interface) {
            if (is_a($fqcn, $interface, true)) {
                return [];
            }
        }

        $constructor = $class->getConstructor();
        $file = $constructor?->getFileName();
        if ($constructor === null || $file === false || $file === null) {
            return [];
        }

        // The answer rests on the constructor's body and on what the hierarchy declares of each property.
        foreach ([...DeclarationFiles::of($fqcn), $file] as $read) {
            ($this->touch)($read);
        }
        $body = $this->files->body($constructor);
        if ($body === null) {
            return [];
        }

        $parameters = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters());
        $declaring = $constructor->getDeclaringClass();
        $resolveName = static fn (Node\Name $name): string => match ($name->toLowerString()) {
            'self' => $declaring->getName(),
            'static' => $fqcn,
            'parent' => (string) get_parent_class($declaring->getName()),
            default => $name->toString(),
        };
        $fold = function (Node\Expr $expr) use ($declaring, $class): ?LiteralT {
            $folded = FixedPropertyValues::fold($expr, $declaring, $class);
            foreach ($folded['files'] ?? [] as $read) {
                ($this->touch)($read);
            }

            return $folded === null ? null : new LiteralT($folded['value']);
        };

        // A promoted parameter is its property's first write: the argument lands before the body runs.
        $first = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isPromoted()) {
                $first[$parameter->getName()] = new Node\Expr\Variable($parameter->getName());
            }
        }
        foreach (ReachedStatements::of($body) as $statement) {
            $written = ReachedStatements::propertyWrite($statement);
            if ($written !== null && ! array_key_exists($written[0], $first)) {
                $first[$written[0]] = $written[1];
            }
        }

        $echoes = [];
        foreach ($first as $name => $expr) {
            if (! self::fixedOnceWritten($class, $name)) {
                continue;
            }

            $read = StatusTextRead::of($expr, $resolveName, $fold, $this->touch);
            $accessor = self::accessor($read === null ? $expr : $read['key'], $parameters, $first, $class);
            if ($accessor !== null) {
                $echoes[$name] = ['accessor' => $accessor, 'text' => $read !== null, 'fallback' => $read['fallback'] ?? null];
            }
        }

        return $echoes;
    }

    /**
     * A parameter accessor, read through `$this->other` where that property's first write is one.
     *
     * @param  list<string>  $parameters
     * @param  array<string, Node\Expr>  $first
     * @param  ReflectionClass<object>  $class
     */
    private static function accessor(Node\Expr $expr, array $parameters, array $first, ReflectionClass $class): ?ParamAccessor
    {
        $property = ReachedStatements::thisProperty($expr);
        if ($property !== null) {
            return isset($first[$property]) && self::readonly($class, $property)
                ? AccessorExtractor::fromExpr($first[$property], $parameters)
                : null;
        }

        return AccessorExtractor::fromExpr($expr, $parameters);
    }

    /**
     * A member of the JSON whose first write is its value, so what every instance holds: public, per instance,
     * and readonly, written by a top-level statement every completing path of the constructor reaches
     * ({@see ReachedStatements}) or promoted. A key read through `$this->other` reads that property's own first
     * write the same way, since a read before it throws.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function fixedOnceWritten(ReflectionClass $class, string $name): bool
    {
        $property = self::property($class, $name);

        return $property !== null && $property->isPublic() && ! $property->isStatic() && $property->isReadOnly();
    }

    /** @param  ReflectionClass<object>  $class */
    private static function readonly(ReflectionClass $class, string $name): bool
    {
        return self::property($class, $name)?->isReadOnly() === true;
    }

    /** @param  ReflectionClass<object>  $class */
    private static function property(ReflectionClass $class, string $name): ?ReflectionProperty
    {
        try {
            return $class->getProperty($name);
        } catch (ReflectionException) {
            return null;
        }
    }
}
