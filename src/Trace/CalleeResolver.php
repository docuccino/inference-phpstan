<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

/**
 * The single call-resolution service for BOTH the {@see Tracer} and the throw
 * analyzer. Previously each had its own: the tracer used a native
 * `ReflectionMethod` (throwing on magic/forwarded calls), the throw analyzer used
 * the PHPStan `ReflectionProvider`. Two reflection stacks could classify the same
 * call differently, so they are unified here on the `ReflectionProvider`.
 *
 * `resolve()` returns null for every "vendor terminal, don't descend" case — a
 * non-method call, an unresolved receiver, a magic/forwarded call
 * (`__call`, e.g. Spatie QB forwarding `paginate`), or a PHP-internal/stub method
 * with no file. That null is exactly the boundary signal both callers act on
 * (Spike B trap #6); the caller then applies its own {@see ProjectFilter} gate.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class CalleeResolver
{
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /**
     * The syntactic callee name (function / method / static-method), or null.
     * Used by the throw registry, which keys on the name regardless of whether
     * the call resolves to a concrete method.
     */
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

    /**
     * Resolve a method/static call to the concrete method it dispatches to
     * (declaring class, method, file). Returns null for the vendor-terminal
     * cases described in the class docblock — the first candidate receiver that
     * resolves wins, so a union receiver is handled deterministically.
     */
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

        // `getObjectClassNames()` preserves the type's member order, so for a given
        // resolved receiver the "first resolvable candidate wins" choice is
        // deterministic across runs — scheduling never affects which callee we pick.
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

            return new Callee($declaring->getName(), $method, $file);
        }

        return null; // magic / forwarded / unresolvable ⇒ vendor terminal
    }
}
