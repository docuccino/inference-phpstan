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
 * The engine-side {@see TypeScope}: the only type-engine surface a visitor sees.
 * Wraps a PHPStan `Scope` + {@see TypeTranslator}; `PhpParser\Node` crosses the
 * boundary while `PHPStan\*` stops here (design §4, Spike B).
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
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
     * The Scramble-Pro-beater. Three cases, in this load-bearing precedence
     * (Spike B):
     *
     *   1. array literal      → recurse per item at the AST level, so factory
     *      calls inside survive as descriptors (PHPStan would flatten them);
     *   2. factory static-call → a call descriptor {factory, args}, folded
     *      BEFORE asking PHPStan for the type (which would collapse it to the
     *      factory's return class);
     *   3. fluent method-call over a descriptor → the SAME descriptor with the
     *      call appended to its chain, so `Rule::enum(...)->only([...])` survives
     *      the AST-level fold (validation §4 #10);
     *   4. enum-case constant → the case NAME as a scalar, so a case referenced
     *      in a fluent arg (`->only([Status::Active])`) is recoverable — a bare
     *      `::class` is left to case 5, and a non-enum class constant keeps its
     *      PHPStan-folded scalar value;
     *   5. genuine literal     → defer to PHPStan constant folding.
     *
     * Returns null when nothing constant is recoverable.
     */
    public function constantValueOf(Node\Expr $expr): ?ConstValue
    {
        // 1. Array literal — items are always ArrayItem in php-parser v5.
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                $items[] = $this->constantValueOf($item->value)
                    ?? ConstValue::unknown('non-constant array item');
            }

            return ConstValue::array($items);
        }

        // 2. Factory static-call — capture the call, do not fold it to its type.
        if ($expr instanceof Node\Expr\StaticCall
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
        ) {
            // Record the FQCN (resolved through the scope's imports/aliases);
            // ConstValue::render() shortens the class for display.
            $factory = $this->scope->resolveName($expr->class).'::'.$expr->name->toString();
            $args = [];
            foreach ($expr->getArgs() as $arg) {
                $args[] = $this->constantValueOf($arg->value)
                    ?? ConstValue::unknown('non-constant factory arg');
            }

            return ConstValue::descriptor($factory, $args);
        }

        // 3. Fluent method-call over a descriptor receiver — append the call to the receiver's chain.
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

        // 4. Enum-case constant (`Status::Active`) → the case name, so fluent args referencing cases
        //    fold. `::class` (a constant string) and non-enum class constants fall through to case 5.
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) !== 'class'
            && enum_exists($this->scope->resolveName($expr->class))
        ) {
            return ConstValue::scalar($expr->name->toString());
        }

        // 5. Genuine literal reached through any expression — let PHPStan fold it (a folded `null` is a
        //    meaningful constant here, so it is kept verbatim rather than treated as "no fold").
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
