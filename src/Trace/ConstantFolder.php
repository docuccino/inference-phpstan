<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use PhpParser\Node;
use PHPStan\Analyser\Scope;

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
     * Seven cases, and the precedence is load-bearing — each earlier one folds at the AST level to stop
     * PHPStan collapsing something we need:
     *
     *   0. a bound parameter → the caller's value, before PHPStan is asked (it only knows the declared type);
     *   1. array literal → recurse per item, so factory calls inside survive as descriptors;
     *   2. factory static-call → a call descriptor {factory, args}, captured before asking PHPStan for the
     *      type (which would collapse it to the factory's return class);
     *   3. fluent method-call over a descriptor → the same descriptor with the call appended, so
     *      `Rule::enum(...)->only([...])` survives;
     *   4. enum-case constant → the case name as a scalar, so `->only([Status::Active])` folds; a bare
     *      `::class` and non-enum class constants fall through;
     *   5. `new X(...)` → an instance value {class, args}: PHPStan would give us the class too, but the
     *      args come from the call site, and a rule object is documentable only by its class;
     *   6. genuine literal → PHPStan's constant folding.
     *
     * A first-class callable (`Foo::bar(...)`) is declined by 2/3/5 and falls through to 6 — null beats a
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
                $items[] = self::fold($item->value, $scope, $bindings)
                    ?? ConstValue::unknown('non-constant array item');
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

        // 3. Fluent call over a descriptor receiver — append to the receiver's chain.
        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && ! $expr->isFirstClassCallable()
        ) {
            $receiver = self::fold($expr->var, $scope, $bindings);
            if ($receiver !== null && $receiver->isDescriptor()) {
                return $receiver->withChainedCall(
                    $expr->name->toString(),
                    self::foldArgs($expr->getArgs(), $scope, $bindings, 'chained-call arg'),
                );
            }
        }

        // 4. Enum-case constant (`Status::Active`) → the case name.
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) !== 'class'
            && enum_exists($scope->resolveName($expr->class))
        ) {
            return ConstValue::scalar($expr->name->toString());
        }

        // 5. `new X(...)` — the class plus its folded constructor args.
        if ($expr instanceof Node\Expr\New_
            && $expr->class instanceof Node\Name
            && ! $expr->isFirstClassCallable()
        ) {
            return ConstValue::instance(
                $scope->resolveName($expr->class),
                self::foldArgs($expr->getArgs(), $scope, $bindings, 'constructor arg'),
            );
        }

        // 6. Let PHPStan fold it. A folded `null` is a meaningful constant here, not a failure to fold.
        $folded = ScalarFold::of($scope->getType($expr));

        return $folded === null ? null : ConstValue::scalar($folded[0]);
    }

    /**
     * @param  array<Node\Arg>  $args
     * @param  array<string, ConstValue>  $bindings
     * @return list<ConstValue>
     */
    private static function foldArgs(array $args, Scope $scope, array $bindings, string $what): array
    {
        $folded = [];
        foreach ($args as $arg) {
            $folded[] = self::fold($arg->value, $scope, $bindings)
                ?? ConstValue::unknown('non-constant '.$what);
        }

        return $folded;
    }
}
