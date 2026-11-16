<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;

/**
 * Where a file, stream or redirect response the framework makes takes its status: the response factory
 * (`response()->stream($cb, 202)`), the redirector (`redirect()->to($url, 301)`), the helpers that are
 * either (`redirect($url, 301)`, `back(303)`, `to_route($name, [], 308)`), a facade proxying one
 * ({@see FacadeCall}), or the class built with `new`. The status sits at whichever parameter the INSTALLED
 * signature names `status`, so a call with no such parameter (`download()`, `file()`, `eventStream()`)
 * states none, and that parameter's default is the status a call passing none is sent with.
 *
 * @internal
 */
final class TransportResponseCall
{
    /** The classes read here; a JSON or plain response is read by its own fold. */
    public const CLASSES = [
        'Symfony\\Component\\HttpFoundation\\BinaryFileResponse',
        'Symfony\\Component\\HttpFoundation\\StreamedResponse',
        'Symfony\\Component\\HttpFoundation\\StreamedJsonResponse',
        'Symfony\\Component\\HttpFoundation\\RedirectResponse',
        'Illuminate\\Http\\RedirectResponse',
    ];

    /** The objects whose methods make one. */
    private const RECEIVERS = [ResponseFactoryCall::CONTRACT, 'Illuminate\\Routing\\Redirector'];

    /** The framework's helpers that make one. */
    private const HELPERS = ['redirect', 'back', 'to_route', 'to_action'];

    private const STATUS = 'status';

    /**
     * The class the call makes, its slots, the position of its status — null where its signature takes
     * none — and the status it defaults to; null for any other call.
     *
     * @return array{class: string, slots: ArgumentSlots, status: int|null, default: int|null}|null
     */
    public static function of(Node\Expr $call, Scope $scope, ReflectionProvider $reflection): ?array
    {
        if ($call instanceof Node\Expr\New_) {
            return self::constructed($call, $scope);
        }

        $class = self::madeClass($call, $scope);
        if ($call instanceof Node\Expr\StaticCall) {
            $call = FacadeCall::onRoot($call, $scope, $reflection);
        }
        if ($class === null || (! $call instanceof Node\Expr\FuncCall && ! $call instanceof Node\Expr\MethodCall) || $call->isFirstClassCallable()) {
            return null;
        }

        $parameters = self::parameters($call, $scope, $reflection);

        return $parameters === null ? null : self::read($class, $call->getArgs(), $parameters);
    }

    /** @return array{class: string, slots: ArgumentSlots, status: int|null, default: int|null}|null */
    private static function constructed(Node\Expr\New_ $new, Scope $scope): ?array
    {
        if (! $new->class instanceof Node\Name || $new->isFirstClassCallable()) {
            return null;
        }

        $class = $scope->resolveName($new->class);
        $constructor = in_array($class, self::CLASSES, true) ? $scope->getMethodReflection(new ObjectType($class), '__construct') : null;

        return $constructor === null ? null : self::read($class, $new->getArgs(), $constructor->getVariants()[0]->getParameters());
    }

    /** The one class in {@see CLASSES} the call is typed as, or null. */
    private static function madeClass(Node\Expr $call, Scope $scope): ?string
    {
        $classes = $scope->getType($call)->getObjectClassNames();

        return count($classes) === 1 && in_array($classes[0], self::CLASSES, true) ? $classes[0] : null;
    }

    /**
     * The parameters of the framework method or helper the call reaches, or null where it reaches neither.
     *
     * @return array<ParameterReflection>|null
     */
    private static function parameters(Node\Expr\FuncCall|Node\Expr\MethodCall $call, Scope $scope, ReflectionProvider $reflection): ?array
    {
        if ($call instanceof Node\Expr\FuncCall) {
            if (! $call->name instanceof Node\Name || ! in_array($reflection->resolveFunctionName($call->name, $scope), self::HELPERS, true)) {
                return null;
            }

            return $reflection->getFunction($call->name, $scope)->getVariants()[0]->getParameters();
        }

        $receiver = $scope->getType($call->var);
        $framework = array_filter(self::RECEIVERS, static fn (string $root): bool => (new ObjectType($root))->isSuperTypeOf($receiver)->yes());
        $method = $call->name instanceof Node\Identifier && $framework !== [] ? $scope->getMethodReflection($receiver, $call->name->toString()) : null;

        return $method?->getVariants()[0]->getParameters();
    }

    /**
     * @param  array<Node\Arg>  $args
     * @param  array<ParameterReflection>  $parameters
     * @return array{class: string, slots: ArgumentSlots, status: int|null, default: int|null}
     */
    private static function read(string $class, array $args, array $parameters): array
    {
        $parameters = array_values($parameters);
        $names = array_map(static fn (ParameterReflection $parameter): string => $parameter->getName(), $parameters);
        $status = array_search(self::STATUS, $names, true);
        $default = $status === false ? null : $parameters[$status]->getDefaultValue()?->getConstantScalarValues();

        return [
            'class' => $class,
            'slots' => ArgumentSlots::of($args, $names),
            'status' => $status === false ? null : $status,
            'default' => $default !== null && count($default) === 1 && is_int($default[0]) ? $default[0] : null,
        ];
    }
}
