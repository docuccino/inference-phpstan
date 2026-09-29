<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Core\Inference\MethodDeclaration;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use Throwable;

/**
 * Whether anything the analyser does not follow may assign a property before a caller holds the object —
 * what makes a constructor path that skips it prove nothing. The analyser follows one level of
 * `$this->method()` into a method the constructor's class declares (its own or a trait's), and nothing
 * else: not a call made from inside that method, not `self::`/`static::`/`parent::` (`parent::__construct()`
 * included), not `$this` handed on as an argument, aliased, used in a closure or written through a dynamic
 * `$this->{$name}`, not the property passed as an argument (it may be by reference). Any of those, or a
 * constructor only the class can call — whose named constructors then decide what an instance holds —
 * leaves the skip unproved.
 *
 * @internal
 */
final class ConstructionEscape
{
    /** @var array<string, list<Node>> file → its name-resolved statements */
    private array $files = [];

    /**
     * Whether one may, given the statements of the constructor `$class` runs.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<Node>  $statements
     */
    public function possible(ReflectionClass $class, string $property, array $statements): bool
    {
        $constructor = $class->getConstructor();
        if ($constructor === null || ! $constructor->isPublic()) {
            return true;
        }

        $declaring = $constructor->getDeclaringClass();
        $followed = [];
        if ($this->escapes($declaring, $property, $statements, $followed)) {
            return true;
        }

        foreach ($followed as $method) {
            $body = $this->methodBody($declaring, $method);
            $nested = [];
            // The analyser follows no call made from inside a followed method.
            if ($body === null || $this->escapes($declaring, $property, $body, $nested) || $nested !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the statements hand the work anywhere unfollowed; each `$this->method()` the analyser follows
     * is collected into `$followed` instead.
     *
     * @param  ReflectionClass<object>  $class  the class declaring the constructor
     * @param  array<Node>  $statements
     * @param  list<string>  $followed
     */
    private function escapes(ReflectionClass $class, string $property, array $statements, array &$followed): bool
    {
        /** @var array<int, true> $harmless the `$this` nodes, by object id, that a fetch or a followed call is made on */
        $harmless = [];
        $finder = new NodeFinder;
        // Pre-order: a fetch or call is seen before the `$this` it is made on.
        $escape = $finder->findFirst($statements, static function (Node $node) use ($class, $property, &$harmless, $finder, &$followed): bool {
            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                return $finder->findFirst($node, self::isThis(...)) !== null;
            }

            if (($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch)
                && self::isThis($node->var) && $node->name instanceof Identifier
            ) {
                $harmless[spl_object_id($node->var)] = true;

                return false;
            }

            if ($node instanceof Expr\MethodCall && self::isThis($node->var) && $node->name instanceof Identifier
                && self::follows($class, $node->name->toString())
            ) {
                $harmless[spl_object_id($node->var)] = true;
                $followed[] = $node->name->toString();

                return false;
            }

            return match (true) {
                $node instanceof Node\Arg => self::fetches($node->value, $property),
                $node instanceof Expr\StaticCall => self::bindsThis($class, $node),
                default => self::isThis($node) && ! isset($harmless[spl_object_id($node)]),
            };
        });

        return $escape !== null;
    }

    /**
     * Whether the analyser merges what a `$this->method()` in the constructor assigns: a non-static method
     * with a body that the constructor's class itself declares.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function follows(ReflectionClass $class, string $method): bool
    {
        if (! $class->hasMethod($method)) {
            return false;
        }

        $reflected = $class->getMethod($method);

        return ! $reflected->isAbstract() && ! $reflected->isStatic()
            && $reflected->getDeclaringClass()->getName() === $class->getName();
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

    /**
     * The statements of a method the class declares, read from the file that writes it (a trait's, for a
     * trait's method), or null where they cannot be found.
     *
     * @param  ReflectionClass<object>  $class
     * @return array<Node>|null
     */
    private function methodBody(ReflectionClass $class, string $method): ?array
    {
        $reflected = $class->getMethod($method);
        $file = $reflected->getFileName();
        if ($file === false) {
            return null;
        }

        return MethodDeclaration::in($this->statements($file), $reflected)?->stmts;
    }

    /** @return list<Node> */
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

        return $this->files[$file] = $statements === null ? [] : array_values((new NodeTraverser(new NameResolver))->traverse($statements));
    }
}
