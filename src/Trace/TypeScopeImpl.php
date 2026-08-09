<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * The engine-side {@see TypeScope} — the only type-engine surface a visitor sees. Wraps a PHPStan `Scope`
 * plus {@see TypeTranslator}: `PhpParser\Node` crosses the boundary, `PHPStan\*` stops here.
 *
 * @internal
 */
final class TypeScopeImpl implements TypeScope
{
    public function __construct(
        private readonly Scope $scope,
        private readonly TypeTranslator $translator,
    ) {}

    public function typeOf(Node\Expr $expr): DType
    {
        return $this->translator->translate($this->scope->getType($expr));
    }

    /**
     * Six cases, and the precedence is load-bearing — each earlier one folds at the AST level to stop
     * PHPStan collapsing something we need:
     *
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
     * Null when nothing constant is recoverable.
     */
    public function constantValueOf(Node\Expr $expr): ?ConstValue
    {
        // 1. Array literal — every item is an ArrayItem in php-parser v5.
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                $items[] = $this->constantValueOf($item->value)
                    ?? ConstValue::unknown('non-constant array item');
            }

            return ConstValue::array($items);
        }

        // 2. Factory static-call — capture the call, don't fold it to its type.
        if ($expr instanceof Node\Expr\StaticCall
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
        ) {
            // Store the FQCN; ConstValue::render() shortens it for display.
            $factory = $this->scope->resolveName($expr->class).'::'.$expr->name->toString();
            $args = [];
            foreach ($expr->getArgs() as $arg) {
                $args[] = $this->constantValueOf($arg->value)
                    ?? ConstValue::unknown('non-constant factory arg');
            }

            return ConstValue::descriptor($factory, $args);
        }

        // 3. Fluent call over a descriptor receiver — append to the receiver's chain.
        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $receiver = $this->constantValueOf($expr->var);
            if ($receiver !== null && $receiver->isDescriptor()) {
                $args = [];
                foreach ($expr->getArgs() as $arg) {
                    $args[] = $this->constantValueOf($arg->value)
                        ?? ConstValue::unknown('non-constant chained-call arg');
                }

                return $receiver->withChainedCall($expr->name->toString(), $args);
            }
        }

        // 4. Enum-case constant (`Status::Active`) → the case name.
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) !== 'class'
            && enum_exists($this->scope->resolveName($expr->class))
        ) {
            return ConstValue::scalar($expr->name->toString());
        }

        // 5. `new X(...)` — the class plus its folded constructor args.
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $args = [];
            foreach ($expr->getArgs() as $arg) {
                $args[] = $this->constantValueOf($arg->value)
                    ?? ConstValue::unknown('non-constant constructor arg');
            }

            return ConstValue::instance($this->scope->resolveName($expr->class), $args);
        }

        // 6. Let PHPStan fold it. A folded `null` is a meaningful constant here, not a failure to fold.
        $folded = ScalarFold::of($this->scope->getType($expr));

        return $folded === null ? null : ConstValue::scalar($folded[0]);
    }

    public function location(Node $node): SourceLocation
    {
        $pos = $node->getStartFilePos();

        return new SourceLocation(
            $this->scope->getFile(),
            $node->getStartLine(),
            $pos < 0 ? null : $pos,
        );
    }
}
