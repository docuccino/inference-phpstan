<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use BackedEnum;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Support\ParsedFiles;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionProperty;
use Throwable;

/**
 * The value a property holds on every instance of its class, where PHP guarantees the class fixes it: a
 * readonly property a final class's constructor assigns once, from a literal — itself or through the
 * `parent::__construct()` it always runs — and that nothing its hierarchy declares can re-initialise on a
 * copy. Rule, and what a source read cannot see, in full: `docs/design/uir-and-extensions.md` §Discriminated unions.
 *
 * @internal
 */
final class FixedPropertyValues
{
    public function __construct(private readonly ParsedFiles $files = new ParsedFiles) {}

    /**
     * The fixed value as a literal, with the files it was copied out of beyond the class's own
     * (an enum's, a constant's declaring class's), or null where the class does not fix it.
     *
     * @param  ReflectionClass<object>  $class  the class being described
     * @param  DType  $declared  the property's type as otherwise recovered, which the value must satisfy
     * @return array{type: LiteralT, files: list<string>}|null
     */
    public function of(ReflectionClass $class, ReflectionProperty $property, DType $declared): ?array
    {
        $constructor = $class->getConstructor();
        if (! $class->isFinal()
            || ! $property->isReadOnly()
            || $property->isPromoted()
            || $constructor === null
            || $class->hasMethod('__clone')
            || self::publicSet($property)
        ) {
            return null;
        }

        if ($this->clonesWith($class)) {
            return null;
        }

        $assigned = $this->assignedBy($constructor->getDeclaringClass(), $property);
        $folded = $assigned === null ? null : self::fold($assigned['expr'], $assigned['scope'], $class);
        if ($folded === null || ! self::satisfies($declared, $folded['value'], $folded['enum'])) {
            return null;
        }

        return ['type' => new LiteralT($folded['value']), 'files' => $folded['files']];
    }

    /**
     * What the constructor `$declaring` writes assigns the property on every path that completes it: a
     * top-level `$this->name = …;` it reaches ({@see ReachedStatements}), or else what the parent constructor
     * it reaches first assigns. Readonly makes whichever runs first the value, since any later write throws.
     * With the class the line sits in, which `self::` binds to.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return array{expr: Node\Expr, scope: ReflectionClass<object>}|null
     */
    private function assignedBy(ReflectionClass $declaring, ReflectionProperty $property): ?array
    {
        $owner = $property->getDeclaringClass()->getName();
        $constructor = $declaring->getConstructor();
        if (($owner !== $declaring->getName() && ! $declaring->isSubclassOf($owner)) || $constructor === null) {
            return null;
        }

        foreach (ReachedStatements::of($this->files->body($constructor) ?? []) as $statement) {
            $expr = ReachedStatements::assignment($statement, $property->getName());
            if ($expr !== null) {
                return ['expr' => $expr, 'scope' => $declaring];
            }

            if (ReachedStatements::parentConstruct($statement) !== null) {
                $parent = ReachedStatements::parentConstructorClass($declaring);

                return $parent === null ? null : $this->assignedBy($parent, $property);
            }
        }

        return null;
    }

    /**
     * A string or int written as a literal or a class constant, with the files a constant was copied out of.
     *
     * @param  ReflectionClass<object>  $scope  the class whose constructor the expression is written in
     * @param  ReflectionClass<object>  $class  the class being described
     * @return array{value: string|int, enum: ?string, files: list<string>}|null
     */
    public static function fold(Node\Expr $expr, ReflectionClass $scope, ReflectionClass $class): ?array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return ['value' => $expr->value, 'enum' => null, 'files' => []];
        }

        if ($expr instanceof Node\Scalar\Int_) {
            return ['value' => $expr->value, 'enum' => null, 'files' => []];
        }

        if (! $expr instanceof Node\Expr\ClassConstFetch || ! $expr->class instanceof Node\Name || ! $expr->name instanceof Node\Identifier) {
            return null;
        }

        // `self` binds to the class the line is written in, `static` to the class being built.
        $owner = match ($expr->class->toLowerString()) {
            'self' => $scope->getName(),
            'static' => $class->getName(),
            default => $expr->class->toString(),
        };
        if (! class_exists($owner) && ! interface_exists($owner)) {
            return null;
        }

        try {
            $constant = new ReflectionClassConstant($owner, $expr->name->toString());
            $value = $constant->getValue();
            $declaring = $constant->getDeclaringClass()->getFileName();
        } catch (Throwable) {
            return null;
        }

        $files = $declaring === false ? [] : [$declaring];

        if ($value instanceof BackedEnum) {
            $enum = $value::class;
            $enumFile = EnumReflection::file($enum);

            return ['value' => $value->value, 'enum' => $enum, 'files' => $enumFile === null ? $files : [...$files, $enumFile]];
        }

        return is_string($value) || is_int($value) ? ['value' => $value, 'enum' => null, 'files' => $files] : null;
    }

    /**
     * Whether an instance could hold the value under the declared type without PHP coercing it: a
     * coerced value is not the one written, and an ill-typed one never finishes constructing.
     */
    private static function satisfies(DType $declared, string|int $value, ?string $enum): bool
    {
        if ($declared instanceof UnionT) {
            foreach ($declared->members as $member) {
                if (self::satisfies($member, $value, $enum)) {
                    return true;
                }
            }

            return false;
        }

        return match (true) {
            $declared instanceof UnknownT => true,
            $declared instanceof EnumT => $declared->fqcn === $enum,
            $declared instanceof ScalarT => $enum === null && $declared->scalar === (is_int($value) ? ScalarT::INT : ScalarT::STRING),
            default => false,
        };
    }

    /** Whether anyone, anywhere, may re-initialise the property on a copy (`public(set)`, PHP 8.4+). */
    private static function publicSet(ReflectionProperty $property): bool
    {
        return method_exists($property, 'isProtectedSet') && method_exists($property, 'isPrivateSet')
            && ! $property->isProtectedSet()
            && ! $property->isPrivateSet();
    }

    /**
     * Whether any scope with set access — the class, its ancestors (readonly is implicitly protected(set)),
     * or a trait any of them uses — may `clone($object, [...])` (PHP 8.5); unreadable counts as yes.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function clonesWith(ReflectionClass $class): bool
    {
        $pending = [$class];
        while ($pending !== []) {
            $scope = array_pop($pending);
            $file = $scope->getFileName();
            $name = $scope->getName();
            $node = $file === false ? null : (new NodeFinder)->findFirst(
                $this->files->statements($file),
                static fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike && $node->namespacedName?->toString() === $name,
            );
            if (! $node instanceof Node\Stmt\ClassLike) {
                return true;
            }

            $withProperties = (new NodeFinder)->findFirst($node, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && $node->name->toLowerString() === 'clone'
                && ($node->isFirstClassCallable() || count($node->getArgs()) !== 1 || $node->getArgs()[0]->unpack));
            if ($withProperties !== null) {
                return true;
            }

            foreach ($scope->getTraits() as $trait) {
                $pending[] = $trait;
            }

            $parent = $scope->getParentClass();
            if ($parent !== false) {
                $pending[] = $parent;
            }
        }

        return false;
    }
}
