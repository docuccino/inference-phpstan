<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;
use PHPStan\Reflection\ParameterReflection;
use ReflectionClass;

/**
 * What the parameters of one call hold, read off its arguments as written ({@see ArgumentSlots}) — the
 * reading a body is typed by when it is read for that one call: a helper handed the exception, a factory's
 * parameters, or the readonly properties a constructor promotes.
 *
 * @phpstan-type Parameter array{name: string, variadic: bool}
 *
 * @internal
 */
final class HeldArguments
{
    /**
     * The analyser's parameters, as this reads them.
     *
     * @param  list<ParameterReflection>  $parameters
     * @return list<Parameter>
     */
    public static function parameters(array $parameters): array
    {
        return array_map(static fn (ParameterReflection $parameter): array => ['name' => $parameter->getName(), 'variadic' => $parameter->isVariadic()], $parameters);
    }

    /**
     * Parameter name → the argument written for it, or null where the call provably passes nothing and the
     * parameter holds its default. A parameter a spread may fill is left out, since nothing here says what
     * it holds, and so is a variadic one, which one argument does not fill.
     *
     * @param  list<Parameter>  $parameters  in declaration order
     * @param  array<Node\Arg>  $args
     * @return array<string, Node\Expr|null>
     */
    public static function of(array $parameters, array $args): array
    {
        $slots = ArgumentSlots::of($args, array_column($parameters, 'name'));

        $held = [];
        foreach ($parameters as $index => $parameter) {
            if (! $parameter['variadic'] && $slots->knows($index)) {
                $held[$parameter['name']] = $slots->at($index);
            }
        }

        return $held;
    }

    /**
     * The parameter a variable is handed to whole, or null where the call hands it to none — or only to a
     * variadic one, which holds a list of it.
     *
     * @param  list<Parameter>  $parameters  in declaration order
     * @param  array<Node\Arg>  $args
     */
    public static function receiving(array $parameters, array $args, string $variable): ?string
    {
        foreach (self::of($parameters, $args) as $name => $arg) {
            if ($arg instanceof Node\Expr\Variable && $arg->name === $variable) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The one `new` a factory returns, or null where it returns anything else or more than once: a factory
     * with two returns builds two objects, and neither is the one a call to it built.
     *
     * @param  list<Node\Expr|null>  $returned  what each of its `return`s returns
     */
    public static function soleConstruction(array $returned): ?Node\Expr\New_
    {
        $new = count($returned) === 1 ? $returned[0] : null;

        return $new instanceof Node\Expr\New_ && $new->class instanceof Node\Name ? $new : null;
    }

    /**
     * The constructor parameters that promote a readonly property. Such a property is written once, before
     * any method of the object runs, so every read of it there is what the construction passed.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    public static function promotedReadonly(ReflectionClass $class): array
    {
        $promoted = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted() && $class->getProperty($parameter->getName())->isReadOnly()) {
                $promoted[] = $parameter->getName();
            }
        }

        return $promoted;
    }
}
