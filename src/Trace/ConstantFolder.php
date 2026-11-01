<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Type;

/**
 * The AST-level constant fold behind {@see TypeScopeImpl::constantValueOf()} and
 * {@see ReturnValueFolder}. One implementation, because a value folded inside a callee body must read
 * identically to one folded at a call site.
 *
 * `$bindings` binds a parameter name to the value the call site passed it, which is what lets a callee's
 * `AllowedFilter::callback($key, …)` fold at all; an unbound variable just falls through to PHPStan.
 *
 * @internal
 */
final class ConstantFolder
{
    /**
     * Six cases, and the precedence is load-bearing — each earlier one folds at the AST level to stop
     * PHPStan collapsing something we need:
     *
     *   0. a bound parameter → the caller's value, before PHPStan is asked (it only knows the declared type);
     *   1. array literal → recurse per item, so factory calls inside survive as descriptors, with a
     *      spread item left standing as one {@see ConstValue::spread()};
     *   2. factory static-call → a call descriptor {factory, args}, captured before asking PHPStan for the
     *      type (which would collapse it to the factory's return class);
     *   3. fluent method-call over a descriptor → the same descriptor with the call appended, so
     *      `Rule::enum(...)->only([...])` survives;
     *   4. `new X(...)` → an instance value {class, args}: PHPStan would give us the class too, but the
     *      args come from the call site, and a rule object is documentable only by its class;
     *   5. anything else → what PHPStan's type pins it to ({@see ofType()}): an enum case such as
     *      `Status::Active` with its enum, or a constant scalar.
     *
     * A first-class callable (`Foo::bar(...)`) is declined by 2/3/4 and falls through to 5 — null beats a
     * descriptor with empty args, which would name a call the code never makes. Null when nothing constant
     * is recoverable.
     *
     * @param  array<string, ConstValue>  $bindings  parameter name → the value bound to it
     */
    public static function fold(Node\Expr $expr, Scope $scope, array $bindings = []): ?ConstValue
    {
        // 0. A bound parameter read.
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && isset($bindings[$expr->name])) {
            return $bindings[$expr->name];
        }

        // 1. Array literal — every item is an ArrayItem in php-parser v5.
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                // A spread item carries however many values its sequence holds, and readers pair these
                // items with the WRITTEN ones one for one — so it stays one item, saying that values
                // nobody can count sit here. Expanding it would shift every pairing after it.
                $items[] = $item->unpack
                    ? ConstValue::spread('spread array item')
                    : self::fold($item->value, $scope, $bindings) ?? ConstValue::unknown('non-constant array item');
            }

            return ConstValue::array($items);
        }

        // 2. Factory static-call — capture the call, don't fold it to its type.
        if ($expr instanceof Node\Expr\StaticCall
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && ! $expr->isFirstClassCallable()
        ) {
            // Store the FQCN; ConstValue::render() shortens it for display.
            $factory = $scope->resolveName($expr->class).'::'.$expr->name->toString();

            return ConstValue::descriptor($factory, self::foldArgs($expr->getArgs(), $scope, $bindings, 'factory arg'));
        }

        // 3. Fluent call over a descriptor or `new` receiver — append to the receiver's chain.
        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && ! $expr->isFirstClassCallable()
        ) {
            $receiver = self::fold($expr->var, $scope, $bindings);
            if ($receiver !== null && ($receiver->isDescriptor() || $receiver->isInstance())) {
                return $receiver->withChainedCall(
                    $expr->name->toString(),
                    self::foldArgs($expr->getArgs(), $scope, $bindings, 'chained-call arg'),
                );
            }
        }

        // 4. `new X(...)` — the class plus its folded constructor args.
        if ($expr instanceof Node\Expr\New_
            && $expr->class instanceof Node\Name
            && ! $expr->isFirstClassCallable()
        ) {
            return ConstValue::instance(
                $scope->resolveName($expr->class),
                self::foldArgs($expr->getArgs(), $scope, $bindings, 'constructor arg'),
            );
        }

        // 5. Let PHPStan fold it.
        return self::ofType($scope->getType($expr));
    }

    /**
     * The constant a type pins: the one enum case it can be, with its enum, else a single constant scalar.
     * A folded `null` is a meaningful constant here, not a failure to fold. The case is read off the type
     * rather than the written name, so an enum constant aliasing a case (`const DEFAULT = self::Normal`)
     * is that case and a plain constant on an enum is its value.
     */
    public static function ofType(Type $type): ?ConstValue
    {
        $cases = $type->getEnumCases();
        if (count($cases) === 1 && $type->isSuperTypeOf($cases[0])->yes() && $cases[0]->isSuperTypeOf($type)->yes()) {
            return ConstValue::enumCase($cases[0]->getClassName(), $cases[0]->getEnumCaseName());
        }

        $folded = ScalarFold::of($type);

        return $folded === null ? null : ConstValue::scalar($folded[0]);
    }

    /**
     * The arguments as POSITIONS, which is the only way {@see ConstValue::$args} can be read: a spread the
     * call site wrote out is expanded into the positions it really takes, and anything else that holds no
     * position of its own — an unreadable spread, a named argument — ends the list with one
     * {@see ConstValue::spread()} standing for the rest ({@see ArgumentSlots} states the rule). Without
     * that marker the slots after it would look absent, and every reader here treats absent as the
     * parameter's default.
     *
     * @param  array<Node\Arg>  $args
     * @param  array<string, ConstValue>  $bindings
     * @return list<ConstValue>
     */
    private static function foldArgs(array $args, Scope $scope, array $bindings, string $what): array
    {
        $slots = ArgumentSlots::of($args);

        $folded = [];
        foreach ($slots->positional() as $expr) {
            $folded[] = self::fold($expr, $scope, $bindings)
                ?? ConstValue::unknown('non-constant '.$what);
        }

        if (! $slots->isIndexable()) {
            $folded[] = ConstValue::spread('unplaceable '.$what);
        }

        return $folded;
    }
}
