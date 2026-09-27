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
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionProperty;
use Throwable;

/**
 * The value a property holds on every instance of its class, where PHP guarantees the class fixes it: a
 * readonly property a final class's own constructor assigns once, from a literal, and that nothing its
 * hierarchy declares can re-initialise on a copy. Rule, and what a source read cannot see, in full:
 * `docs/design/uir-and-extensions.md` §Discriminated unions.
 *
 * @internal
 */
final class FixedPropertyValues
{
    /** @var array<string, list<Node\Stmt>> file → its name-resolved statements */
    private array $files = [];

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
            || $property->getDeclaringClass()->getName() !== $class->getName()
            || $constructor === null
            || $constructor->getDeclaringClass()->getName() !== $class->getName()
            || $class->hasMethod('__clone')
            || self::publicSet($property)
        ) {
            return null;
        }

        $file = $constructor->getFileName();
        $method = $file === false ? null : $this->constructorAt($file, $constructor->getStartLine());
        if ($method === null) {
            return null;
        }

        if ($this->clonesWith($class)) {
            return null;
        }

        $expr = self::assignedAtTop($method->stmts ?? [], $property->getName());
        $folded = $expr === null ? null : self::fold($expr, $class);
        if ($folded === null || ! self::satisfies($declared, $folded['value'], $folded['enum'])) {
            return null;
        }

        return ['type' => new LiteralT($folded['value']), 'files' => $folded['files']];
    }

    /**
     * What a top-level `$this->name = …;` assigns, when no statement before it could leave the
     * constructor first.
     *
     * @param  array<Node\Stmt>  $statements
     */
    private static function assignedAtTop(array $statements, string $name): ?Node\Expr
    {
        $finder = new NodeFinder;
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Expression
                && $statement->expr instanceof Node\Expr\Assign
                && $statement->expr->var instanceof Node\Expr\PropertyFetch
                && $statement->expr->var->var instanceof Node\Expr\Variable
                && $statement->expr->var->var->name === 'this'
                && $statement->expr->var->name instanceof Node\Identifier
                && $statement->expr->var->name->toString() === $name
            ) {
                return $statement->expr->expr;
            }

            if ($finder->findFirst($statement, static fn (Node $node): bool => $node instanceof Node\Stmt\Return_ || $node instanceof Node\Stmt\Goto_) !== null) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array{value: string|int, enum: ?string, files: list<string>}|null
     */
    private static function fold(Node\Expr $expr, ReflectionClass $class): ?array
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

        // `static` is `self` here: the class is final.
        $owner = in_array($expr->class->toLowerString(), ['self', 'static'], true) ? $class->getName() : $expr->class->toString();
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
                $this->statements($file),
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

    /** The `__construct` declared at this line of the file, or null. */
    private function constructorAt(string $file, int|false $line): ?Node\Stmt\ClassMethod
    {
        $found = (new NodeFinder)->findFirst(
            $this->statements($file),
            static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toLowerString() === '__construct'
                && $node->getStartLine() === $line,
        );

        return $found instanceof Node\Stmt\ClassMethod ? $found : null;
    }

    /** @return list<Node\Stmt> */
    private function statements(string $file): array
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

        $traverser = new NodeTraverser(new NameResolver);

        return $this->files[$file] = array_values(array_filter(
            $traverser->traverse($statements),
            static fn (Node $node): bool => $node instanceof Node\Stmt,
        ));
    }
}
