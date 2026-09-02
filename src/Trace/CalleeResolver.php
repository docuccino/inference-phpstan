<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionException;

/**
 * The one call-resolution service for both the {@see Tracer} and the throw analyzer, on PHPStan's
 * `ReflectionProvider` — two reflection stacks would classify the same call differently.
 *
 * `resolve()` returns null for every "vendor terminal, don't descend" case: a non-method call, an unresolved
 * receiver, a magic/forwarded call (`__call`, e.g. Spatie QB forwarding `paginate`), or a PHP-internal/stub
 * method with no file. That null is the boundary signal both callers act on; each then applies its own
 * {@see ProjectFilter} gate.
 *
 * @internal
 */
final class CalleeResolver
{
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /** The throw registry keys on this whether or not the call resolves to a concrete method. */
    public function name(Node $node): ?string
    {
        if ($node instanceof Node\Expr\FuncCall) {
            return $node->name instanceof Node\Name ? $node->name->toString() : null;
        }

        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier
        ) {
            return $node->name->toString();
        }

        return null;
    }

    /** Null for the vendor-terminal cases above; the first resolvable receiver candidate wins. */
    public function resolve(Node $node, Scope $scope): ?Callee
    {
        if ($node instanceof Node\Expr\MethodCall) {
            if (! $node->name instanceof Node\Identifier) {
                return null;
            }
            $method = $node->name->toString();
            $classNames = $scope->getType($node->var)->getObjectClassNames();
        } elseif ($node instanceof Node\Expr\StaticCall) {
            if (! $node->name instanceof Node\Identifier || ! $node->class instanceof Node\Name) {
                return null;
            }
            $method = $node->name->toString();
            $classNames = [$scope->resolveName($node->class)];
        } else {
            return null;
        }

        // `getObjectClassNames()` preserves member order, so "first resolvable wins" is deterministic
        // across runs even for a union receiver.
        foreach ($classNames as $class) {
            if (! $this->reflectionProvider->hasClass($class)) {
                continue;
            }
            $classReflection = $this->reflectionProvider->getClass($class);
            if (! $classReflection->hasMethod($method)) {
                continue;
            }
            $declaring = $classReflection->getMethod($method, $scope)->getDeclaringClass();
            $file = $declaring->getFileName();
            if ($file === null) {
                return null; // PHP-internal / stub-only ⇒ vendor terminal
            }

            return new Callee($declaring->getName(), $method, $file, self::writtenIn($declaring, $method));
        }

        return null; // magic / forwarded / unresolvable ⇒ vendor terminal
    }

    /**
     * The trace's own root, which arrives as a class/method/file rather than as a call to resolve: the
     * declaration read is the same one every callee gets, so a trait-imported action is keyed like a
     * trait-imported callee.
     */
    public function root(string $class, string $method, string $file): Callee
    {
        $declaring = $this->reflectionProvider->hasClass($class) ? $this->reflectionProvider->getClass($class) : null;

        return new Callee($class, $method, $file, $declaring === null ? null : self::writtenIn($declaring, $method));
    }

    /**
     * Where the method's own body is written, which for a TRAIT's method is not the declaring class's file:
     * PHP reports the member as the using class's, and only asking the METHOD names the file it was copied
     * from. Asked through the analyser's own reflection, which locates a declaration by reading files —
     * never `new ReflectionMethod($name, …)`, whose first act is to autoload `$name`, and autoloading a
     * class executes the file that declares it. This runs for every callee a trace resolves, vendor
     * included, so that would be the generator running arbitrary analysed code: a top-level side effect,
     * or a declaration that fatals with an `E_COMPILE_ERROR` no `catch` can reach.
     *
     * Null wherever the declaration cannot be located — a stub, a magic forward — leaving
     * {@see Callee::writtenIn()} on the declaring class's own file.
     */
    private static function writtenIn(ClassReflection $class, string $method): ?string
    {
        try {
            $file = $class->getNativeReflection()->getMethod($method)->getFileName();
        } catch (ReflectionException) {
            return null; // a member only the provider knows: an `@method`, a magic forward
        }

        return $file === false ? null : $file;
    }
}
