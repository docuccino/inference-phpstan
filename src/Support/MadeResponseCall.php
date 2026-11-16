<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;

/**
 * Where a plain response the factory makes takes its body, status and headers: `make($content, $status,
 * $headers)` and `view($view, $data, $status, $headers)`, called on the factory or through a facade proxying
 * it ({@see FacadeCall}), or as the `response($content, $status, $headers)` helper, which is `make()`. Every
 * spelling reads through here, so a status is read the same whichever one an application wrote.
 *
 * @internal
 */
final class MadeResponseCall
{
    private const HELPER = 'response';

    /** Each method's parameters, in order: the slots a named argument lands in. */
    private const PARAMETERS = [
        'make' => ['content', 'status', 'headers'],
        'view' => ['view', 'data', 'status', 'headers'],
    ];

    /**
     * The call's slots, where its status and headers sit, and whether its body is the view it renders or the
     * content in its first slot ({@see PlainContent}); null for any other call. The helper with no arguments
     * is the factory itself, not a response.
     *
     * @return array{view: bool, slots: ArgumentSlots, status: int, headers: int}|null
     */
    public static function of(Node\Expr $call, Scope $scope, ReflectionProvider $reflection): ?array
    {
        if ($call instanceof Node\Expr\StaticCall) {
            $call = FacadeCall::onRoot($call, $scope, $reflection);
        }
        if (! $call instanceof Node\Expr\FuncCall && ! $call instanceof Node\Expr\MethodCall) {
            return null;
        }

        $method = $call instanceof Node\Expr\FuncCall
            ? self::helper($call, $scope, $reflection)
            : ((new ObjectType(ResponseFactoryCall::CONTRACT))->isSuperTypeOf($scope->getType($call->var))->yes() ? self::method($call->name) : null);
        if ($method === null || $call->isFirstClassCallable()) {
            return null;
        }

        // Both signatures end `$status, array $headers`.
        $status = count(self::PARAMETERS[$method]) - 2;
        $slots = ArgumentSlots::of($call->getArgs(), self::PARAMETERS[$method]);

        return [
            'view' => $method === 'view',
            'slots' => $slots,
            'status' => $status,
            'headers' => $status + 1,
        ];
    }

    private static function helper(Node\Expr\FuncCall $call, Scope $scope, ReflectionProvider $reflection): ?string
    {
        if (! $call->name instanceof Node\Name || $call->isFirstClassCallable() || $call->getArgs() === []) {
            return null;
        }

        return $reflection->resolveFunctionName($call->name, $scope) === self::HELPER ? 'make' : null;
    }

    private static function method(Node\Identifier|Node\Expr $name): ?string
    {
        // PHP method names are case-insensitive.
        return $name instanceof Node\Identifier && isset(self::PARAMETERS[$name->toLowerString()]) ? $name->toLowerString() : null;
    }
}
