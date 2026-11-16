<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Closure;
use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Inference\PhpStan\Analysis\CallableBody;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use RuntimeException;
use Throwable;

/**
 * What Laravel's `throw_if()` and `throw_unless()` throw, read off the argument they are handed, each as the
 * `throw` it stands for: an instance is thrown as it is, a class name is built with the parameters after it,
 * and any other string is the message of a `RuntimeException`. A closure is called first, and what it returns
 * is read the same way. The helpers' `@throws` names at most the class; the construction is in the argument.
 *
 * @phpstan-type HandedThrow array{Node\Expr\Throw_, Scope, Type}
 *
 * @internal
 */
final class HandedException
{
    private const HELPERS = ['throw_if', 'throw_unless'];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public static function handles(string $function): bool
    {
        return in_array(strtolower($function), self::HELPERS, true);
    }

    /**
     * The `throw` each exception the call can be handed stands for, written at the call, with the scope it is
     * read in and the class it throws. Null where what it is handed cannot be read — a value chosen at run
     * time, a spread — and the helper's declaration stands.
     *
     * @param  Closure(Node\Expr, Scope): ?CallableBody  $closure  the closure an argument is, where the body writes it
     * @return list<HandedThrow>|null
     */
    public function of(Node\Expr\CallLike $call, FunctionReflection $function, Scope $scope, Closure $closure): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $names = [];
        foreach ($function->getVariants()[0]->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        $slots = ArgumentSlots::of($call->getArgs(), $names);
        $parameters = $this->parameters($call->getArgs(), array_slice($names, 0, 2));
        if (! $slots->knows(1) || $parameters === null) {
            return null;
        }

        $handed = $slots->at(1);
        if ($handed === null) {
            // The default is the name of `RuntimeException`, built with the parameters as any name is.
            return [$this->built(RuntimeException::class, $parameters, $call, $scope)];
        }

        $value = $this->read($handed, $scope, $parameters, $call);
        if ($value !== null) {
            return $value;
        }

        // Only a closure is called; what it returns is handed on as if it were the argument.
        $body = $closure($handed, $scope);
        if ($body === null || $body->returns === []) {
            return null;
        }

        $throws = [];
        foreach ($body->returns as $return) {
            $read = $return['expr'] === null ? null : $this->read($return['expr'], $return['scope'], $parameters, $call);
            if ($read === null) {
                return null;
            }
            $throws = [...$throws, ...$read];
        }

        return $throws;
    }

    /**
     * @param  list<Node\Arg>  $parameters
     * @return list<HandedThrow>|null
     */
    private function read(Node\Expr $value, Scope $scope, array $parameters, Node\Expr\CallLike $call): ?array
    {
        $type = $scope->getType($value);

        $strings = $type->getConstantStrings();
        if ($type->isString()->yes() && count($strings) === 1) {
            $name = $strings[0]->getValue();
            if (! $this->reflectionProvider->hasClass($name)) {
                return [$this->built(RuntimeException::class, [new Node\Arg($value)], $call, $scope)];
            }

            $class = $this->reflectionProvider->getClass($name);

            return $class->isClass() && ! $class->isAbstract() && $class->implementsInterface(Throwable::class)
                ? [$this->built($class->getName(), $parameters, $call, $scope)]
                : null;
        }

        return (new ObjectType(Throwable::class))->isSuperTypeOf($type)->yes()
            ? [[new Node\Expr\Throw_($value, $call->getAttributes()), $scope, $type]]
            : null;
    }

    /**
     * The arguments after the condition and the exception, in the order written, which Laravel spreads into
     * the constructor — so a name written for one binds the constructor's parameter of that name. Null where a
     * spread hides which argument is which.
     *
     * @param  array<Node\Arg>  $args
     * @param  list<string>  $taken  the condition's and the exception's parameter names
     * @return list<Node\Arg>|null
     */
    private function parameters(array $args, array $taken): ?array
    {
        $parameters = [];
        $position = 0;
        foreach ($args as $arg) {
            if ($arg->name !== null) {
                if (! in_array($arg->name->toString(), $taken, true)) {
                    $parameters[] = $arg;
                }

                continue;
            }

            if ($position < 2 && $arg->unpack) {
                return null;
            }

            if ($position++ >= 2) {
                $parameters[] = $arg;
            }
        }

        return $parameters;
    }

    /**
     * @param  list<Node\Arg>  $args
     * @return HandedThrow
     */
    private function built(string $class, array $args, Node\Expr\CallLike $call, Scope $scope): array
    {
        $new = new Node\Expr\New_(new Node\Name\FullyQualified($class), $args, $call->getAttributes());

        return [new Node\Expr\Throw_($new, $call->getAttributes()), $scope, new ObjectType($class)];
    }
}
