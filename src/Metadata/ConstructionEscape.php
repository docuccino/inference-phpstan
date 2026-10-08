<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Inference\PhpStan\Support\ParsedFiles;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use ReflectionClass;

/**
 * Whether anything the analyser does not follow may assign a property before a caller holds the object —
 * what makes a constructor path that skips it prove nothing. What it follows, and what it does not:
 * `docs/design/inference-embedding.md` §Which keys a constructed object always carries.
 *
 * @internal
 */
final class ConstructionEscape
{
    public function __construct(private readonly ParsedFiles $files = new ParsedFiles) {}

    /**
     * Whether one may, given the statements of the constructor `$declaring` writes, run to build a `$class`.
     * With `$parent`, that call is answered for elsewhere and any use of the property counts.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $declaring
     * @param  array<Node>  $statements
     */
    public function possible(ReflectionClass $class, ReflectionClass $declaring, string $property, array $statements, ?Expr\StaticCall $parent = null): bool
    {
        $constructor = $class->getConstructor();
        if ($constructor === null || ! $constructor->isPublic()) {
            return true;
        }

        $inherited = $parent !== null;
        $followed = [];
        if ($this->escapes($class, $declaring, $property, $statements, $followed, $inherited, $parent)) {
            return true;
        }

        foreach ($followed as $method) {
            $body = $this->files->body($declaring->getMethod($method));
            $nested = [];
            // The analyser follows no call made from inside a followed method.
            if ($body === null || $this->escapes($class, $declaring, $property, $body, $nested, $inherited) || $nested !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every `$this->method()` the analyser follows out of these statements runs, on a `$class`, the
     * declaration it followed — false where the class being built overrides one, so the analysed body is not
     * the one PHP runs.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $declaring  the class declaring the constructor
     * @param  array<Node>  $statements
     */
    public function dispatches(ReflectionClass $class, ReflectionClass $declaring, array $statements): bool
    {
        return (new NodeFinder)->findFirst($statements, static fn (Node $node): bool => $node instanceof Expr\MethodCall
            && self::isThis($node->var)
            && $node->name instanceof Identifier
            && self::declares($declaring, $node->name->toString())
            && ! self::runs($class, $declaring, $node->name->toString())) === null;
    }

    /**
     * Whether the statements, or a `$this->method()` of `$declaring`'s they call that a `$class` runs, unset
     * the property — by name or through a dynamic `$this->{$name}`. The analyser tracks no unset.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $declaring
     * @param  array<Node>  $statements
     */
    public function unsets(ReflectionClass $class, ReflectionClass $declaring, string $property, array $statements): bool
    {
        $bodies = [$statements];
        foreach ((new NodeFinder)->findInstanceOf($statements, Expr\MethodCall::class) as $call) {
            if (self::isThis($call->var) && $call->name instanceof Identifier
                && self::declares($declaring, $call->name->toString()) && self::runs($class, $declaring, $call->name->toString())
            ) {
                $bodies[] = $this->files->body($declaring->getMethod($call->name->toString())) ?? [];
            }
        }

        foreach ($bodies as $body) {
            foreach ((new NodeFinder)->findInstanceOf($body, Node\Stmt\Unset_::class) as $unset) {
                foreach ($unset->vars as $var) {
                    if ($var instanceof Expr\PropertyFetch && self::isThis($var->var)
                        && (! $var->name instanceof Identifier || $var->name->toString() === $property)
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether the statements hand the work anywhere unfollowed; each `$this->method()` the analyser follows
     * is collected into `$followed` instead.
     *
     * @param  ReflectionClass<object>  $class  the class being built
     * @param  ReflectionClass<object>  $declaring  the class declaring the constructor
     * @param  array<Node>  $statements
     * @param  list<string>  $followed
     */
    private function escapes(ReflectionClass $class, ReflectionClass $declaring, string $property, array $statements, array &$followed, bool $inherited, ?Expr\StaticCall $parent = null): bool
    {
        /** @var array<int, true> $harmless the `$this` nodes, by object id, that a fetch or a followed call is made on */
        $harmless = [];
        $finder = new NodeFinder;
        // Pre-order: a fetch or call is seen before the `$this` it is made on.
        $escape = $finder->findFirst($statements, static function (Node $node) use ($class, $declaring, $property, $inherited, $parent, &$harmless, $finder, &$followed): bool {
            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                return $finder->findFirst($node, self::isThis(...)) !== null;
            }

            if (($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch)
                && self::isThis($node->var) && $node->name instanceof Identifier
            ) {
                $harmless[spl_object_id($node->var)] = true;

                return $inherited && $node->name->toString() === $property;
            }

            if ($node instanceof Expr\MethodCall && self::isThis($node->var) && $node->name instanceof Identifier
                && self::declares($declaring, $node->name->toString()) && self::runs($class, $declaring, $node->name->toString())
            ) {
                $harmless[spl_object_id($node->var)] = true;
                $followed[] = $node->name->toString();

                return false;
            }

            return match (true) {
                $node === $parent => false,
                $node instanceof Node\Arg => self::fetches($node->value, $property),
                $node instanceof Expr\StaticCall => self::bindsThis($declaring, $node),
                default => self::isThis($node) && ! isset($harmless[spl_object_id($node)]),
            };
        });

        return $escape !== null;
    }

    /**
     * Whether the analyser merges what a `$this->method()` in the constructor assigns: a non-static method
     * with a body that the constructor's class itself declares.
     *
     * @param  ReflectionClass<object>  $declaring
     */
    private static function declares(ReflectionClass $declaring, string $method): bool
    {
        if (! $declaring->hasMethod($method)) {
            return false;
        }

        $reflected = $declaring->getMethod($method);

        return ! $reflected->isAbstract() && ! $reflected->isStatic()
            && $reflected->getDeclaringClass()->getName() === $declaring->getName();
    }

    /**
     * Whether `$this->method()`, written in `$declaring`, runs `$declaring`'s own method on a `$class`: a
     * private one always does, any other unless a subclass overrides it.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $declaring
     */
    private static function runs(ReflectionClass $class, ReflectionClass $declaring, string $method): bool
    {
        return $declaring->getMethod($method)->isPrivate()
            || ($class->hasMethod($method) && $class->getMethod($method)->getDeclaringClass()->getName() === $declaring->getName());
    }

    private static function isThis(Node $node): bool
    {
        return $node instanceof Expr\Variable && $node->name === 'this';
    }

    /** Whether `$expr` is `$this->name` for the property asked about. */
    private static function fetches(Expr $expr, string $property): bool
    {
        return ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch)
            && self::isThis($expr->var) && $expr->name instanceof Identifier && $expr->name->toString() === $property;
    }

    /**
     * Whether a `Name::method()` call runs on `$this`: a non-static method of the class or an ancestor, or
     * one that cannot be resolved (a dynamic class or name, `__callStatic`).
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function bindsThis(ReflectionClass $class, Expr\StaticCall $call): bool
    {
        if (! $call->class instanceof Name) {
            return true;
        }

        $named = $call->class->toLowerString();
        $owner = match (true) {
            $named === 'self', $named === 'static' => $class,
            $named === 'parent' => $class->getParentClass(),
            default => self::ancestor($class, $call->class->toString()),
        };
        if ($owner === false) {
            return $named === 'parent';
        }

        if (! $call->name instanceof Identifier || ! $owner->hasMethod($call->name->toString())) {
            return true;
        }

        return ! $owner->getMethod($call->name->toString())->isStatic();
    }

    /**
     * The class itself or the ancestor with this name, or false where it names neither.
     *
     * @param  ReflectionClass<object>  $class
     * @return ReflectionClass<object>|false
     */
    private static function ancestor(ReflectionClass $class, string $name): ReflectionClass|false
    {
        for ($candidate = $class; $candidate !== false; $candidate = $candidate->getParentClass()) {
            if (strcasecmp($candidate->getName(), $name) === 0) {
                return $candidate;
            }
        }

        return false;
    }
}
