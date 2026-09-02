<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionException;

/**
 * The files a folded status was WRITTEN in, beyond the body that names it: where an expression reaches a
 * class constant, the file that constant is declared in.
 *
 * A status spelled `Response::HTTP_CONFLICT` or `HttpStatus::CONFLICT` is decided somewhere the body is
 * not, and a fragment whose dependency set names only the body stays warm when that declaration changes —
 * so the route goes on publishing the old number with nothing stale to notice. Under-keying is a
 * correctness bug, so this over-approximates: every class constant the expression names, whether or not it
 * was the one that folded, and whether or not the fold succeeded at all.
 *
 * Read off the DECLARATION and never by evaluating it, for the reason {@see ClassBodies::intDefault()}
 * gives: `ReflectionClassConstant` answers where a constant is written without running its initialiser.
 *
 * @internal
 */
final class ConstantSource
{
    /**
     * Every file declaring a class constant `$expr` names, in the order they are written. `$self` is the
     * class the expression is written in, which is what `self`/`static` name there; a caller with no class
     * to give passes null and those resolve to nothing.
     *
     * @return list<string>
     */
    public static function files(Node\Expr $expr, ?string $self): array
    {
        $files = [];

        /** @var list<Node\Expr\ClassConstFetch> $fetches */
        $fetches = (new NodeFinder)->findInstanceOf($expr, Node\Expr\ClassConstFetch::class);
        foreach ($fetches as $fetch) {
            $file = self::declaringFile($fetch, $self);
            if ($file !== null && ! in_array($file, $files, true)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * The file declaring a constant named the way reflection reports a parameter default —
     * `Some\Class::NAME`, or a bare name for a global one. Null for anything else, a global constant
     * included: `define()`d values carry no declaration site a build can read before PHP 8.4.
     */
    public static function fileForName(string $name, ?string $self): ?string
    {
        $split = strrpos($name, '::');
        if ($split === false) {
            return null;
        }

        $class = substr($name, 0, $split);
        $lowered = strtolower($class);

        return self::fileOf(
            in_array($lowered, ['self', 'static', 'parent'], true) ? self::relative($lowered, $self) : $class,
            substr($name, $split + 2),
        );
    }

    private static function declaringFile(Node\Expr\ClassConstFetch $fetch, ?string $self): ?string
    {
        if (! $fetch->class instanceof Node\Name || ! $fetch->name instanceof Node\Identifier) {
            return null; // `$class::FOO`, `Foo::{$name}` — a name only the running program knows
        }

        return self::fileOf(self::className($fetch->class, $self), $fetch->name->toString());
    }

    private static function fileOf(?string $class, string $constant): ?string
    {
        // `class_exists()` is what turns the name into one reflection may take, the same way the status
        // read spells one out before every `ReflectionClass`.
        if ($class === null || (! class_exists($class) && ! interface_exists($class))) {
            return null;
        }

        try {
            // The declaring class, not the one written: a constant reached through a subclass or an
            // interface is edited where it is declared, which is the file that has to invalidate.
            $file = (new ReflectionClassConstant($class, $constant))->getDeclaringClass()->getFileName();
        } catch (ReflectionException) {
            return null; // `Foo::class`, and any name this build cannot reflect on
        }

        return $file === false ? null : $file;
    }

    private static function className(Node\Name $name, ?string $self): ?string
    {
        return $name->isSpecialClassName()
            ? self::relative($name->toLowerString(), $self)
            : $name->toString();
    }

    /** What `self`, `static` or `parent` names in a body written in `$self`. */
    private static function relative(string $keyword, ?string $self): ?string
    {
        // A caller with no class to give — the registry folds a call PHPStan normalised, with no class body
        // around it — names nothing relative, and guessing one would record a file the line never read.
        if ($self === null) {
            return null;
        }

        // `self` and `static` are both the class the line is written in: this read is over a declaration,
        // where late binding has nothing to bind to.
        if ($keyword !== 'parent') {
            return $self;
        }

        if (! class_exists($self)) {
            return null;
        }

        $parent = (new ReflectionClass($self))->getParentClass();

        return $parent === false ? null : $parent->getName();
    }
}
