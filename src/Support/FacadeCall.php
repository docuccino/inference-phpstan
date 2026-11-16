<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use Throwable;

/**
 * A facade's static call read as the same call on the object the facade proxies, so whatever reads a call on
 * that object reads `Response::json(…)` as it reads `response()->json(…)`. The object is the one the facade
 * resolves in the booted application, as Larastan resolves a facade's methods, and the receiver is a virtual
 * node typed as its class.
 *
 * @internal
 */
final class FacadeCall
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';

    /** The call on the facade's root; null for a call on anything but a facade, or one whose root won't resolve. */
    public static function onRoot(Node\Expr\StaticCall $call, Scope $scope, ReflectionProvider $reflection): ?Node\Expr\MethodCall
    {
        if (! $call->class instanceof Node\Name) {
            return null;
        }

        // Only a class the analyser can read is loaded to ask: a call it cannot type has no call to stand in for.
        $class = $scope->resolveName($call->class);
        if (! $reflection->hasClass($class) || ! is_a($class, self::FACADE, true)) {
            return null;
        }

        $root = self::root($class);

        return $root === null ? null : new Node\Expr\MethodCall(new TypeExpr(new ObjectType($root)), $call->name, $call->args, $call->getAttributes());
    }

    /** The class of the object the facade resolves, or null where it resolves none. */
    private static function root(string $facade): ?string
    {
        $resolve = [$facade, 'getFacadeRoot'];
        if (! is_callable($resolve)) {
            return null;
        }

        try {
            $root = $resolve();
        } catch (Throwable) {
            return null;
        }

        return is_object($root) ? $root::class : null;
    }
}
