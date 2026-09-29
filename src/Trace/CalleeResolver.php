<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
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
        $receivers = self::receivers($node, $scope);
        if ($receivers === null) {
            return null;
        }
        [$method, $classNames] = $receivers;

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
            $declaring = self::dispatched($classReflection, $method, $scope)->getDeclaringClass();
            $file = $declaring->getFileName();
            if ($file === null) {
                return null; // PHP-internal / stub-only ⇒ vendor terminal
            }

            return new Callee($declaring->getName(), $method, $file, self::writtenIn($declaring, $method));
        }

        return null; // magic / forwarded / unresolvable ⇒ vendor terminal
    }

    /**
     * The real method PHP runs for this call where the analyser read the call off something else. A
     * `@method` tag naming a method the class really has — inherited or its own — is read as a magic
     * method, and borrows `__call()`'s or `__callStatic()`'s `@throws`; but PHP forwards only a call it
     * cannot dispatch, so an accessible real method is what runs and what the call can throw. Null where
     * the analyser's reading already throws what that method does, or where no such method is reachable.
     */
    public function shadowedMethod(Node $node, Scope $scope): ?ExtendedMethodReflection
    {
        $receivers = self::receivers($node, $scope);
        if ($receivers === null) {
            return null;
        }
        [$method, $classNames] = $receivers;

        foreach ($classNames as $class) {
            if (! $this->reflectionProvider->hasClass($class)) {
                continue;
            }
            $classReflection = $this->reflectionProvider->getClass($class);
            if (! $classReflection->hasMethod($method)) {
                continue;
            }

            $dispatched = self::dispatched($classReflection, $method, $scope);
            $read = $classReflection->getMethod($method, $scope);

            return self::sameThrows($read->getThrowType(), $dispatched->getThrowType()) ? null : $dispatched;
        }

        return null;
    }

    /**
     * The method name a call names and the classes it may be called on, or null for anything but a
     * method or static call with a literal name.
     *
     * @return array{string, list<string>}|null
     */
    private static function receivers(Node $node, Scope $scope): ?array
    {
        if ($node instanceof Node\Expr\MethodCall) {
            if (! $node->name instanceof Node\Identifier) {
                return null;
            }

            return [$node->name->toString(), $scope->getType($node->var)->getObjectClassNames()];
        }

        if ($node instanceof Node\Expr\StaticCall) {
            if (! $node->name instanceof Node\Identifier || ! $node->class instanceof Node\Name) {
                return null;
            }

            return [$node->name->toString(), [$scope->resolveName($node->class)]];
        }

        return null;
    }

    /**
     * The method a call on this class reaches: its real one wherever the calling scope may call it, since
     * PHP hands `__call()`/`__callStatic()` only a call it cannot dispatch; otherwise whatever the
     * analyser resolves — a `@method` tag, a mixin, an extension's method.
     */
    private static function dispatched(ClassReflection $class, string $method, Scope $scope): ExtendedMethodReflection
    {
        if ($class->hasNativeMethod($method)) {
            $native = $class->getNativeMethod($method);
            if ($scope->canCallMethod($native)) {
                return $native;
            }
        }

        return $class->getMethod($method, $scope);
    }

    private static function sameThrows(?Type $read, ?Type $dispatched): bool
    {
        return $read === null ? $dispatched === null : ($dispatched !== null && $read->equals($dispatched));
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
