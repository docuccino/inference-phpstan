<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\FoldsCallReturns;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Folds the value a call RETURNS, for a visitor that asked the {@see Tracer} to defer one
 * ({@see FoldsCallReturns}). A call site's written arguments fold without opening the callee at all; a name
 * that lives in the callee's body does not, and this is that other half.
 *
 * Only a SINGLE unconditional `return <expr>;` folds. A branching body would need a choice made between its
 * arms and there is no honest one, so it declines and the visitor degrades — honest-permissive, per
 * docs/design/inference-embedding.md §4a.
 *
 * Termination needs no cycle guard: this reads one method body and never folds a call inside it, so
 * `fn () => $this->itself()` simply fails to fold.
 *
 * @internal
 */
final class ReturnValueFolder
{
    public function __construct(
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /**
     * @param  list<ConstValue>  $positional  the call site's positional arguments, already folded
     * @param  array<string, ConstValue>  $named  its named ones
     */
    public function fold(Callee $callee, array $positional, array $named): ?FoldedReturn
    {
        $node = $this->fileAnalyzer->method($callee->file, $callee->class, $callee->method);
        if ($node === null) {
            return null;
        }

        $returns = $node->getReturnStatements();
        // One return, and reaching the end of the body is impossible: anything else is a branch.
        if (count($returns) !== 1 || ! $node->getStatementResult()->isAlwaysTerminating()) {
            return null;
        }

        $expr = $returns[0]->getReturnNode()->expr;
        if ($expr === null) {
            return null;
        }

        $value = ConstantFolder::fold(
            $expr,
            $this->fileAnalyzer->stableScope($returns[0]->getScope()),
            $this->bindings($callee, $positional, $named),
        );

        return $value === null ? null : new FoldedReturn($value, $expr);
    }

    /**
     * Parameter name → the value flowing into it: the call site's argument, else the parameter's own
     * constant default (`status(string $key = 'status')` is a real zero-argument factory). Binding stops at
     * a variadic parameter, where positional mapping stops meaning anything.
     *
     * @param  list<ConstValue>  $positional
     * @param  array<string, ConstValue>  $named
     * @return array<string, ConstValue>
     */
    private function bindings(Callee $callee, array $positional, array $named): array
    {
        $bindings = [];
        foreach ($this->parameters($callee) as $index => $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $argument = $positional[$index] ?? $named[$parameter->getName()] ?? null;
            if ($argument === null) {
                $default = $parameter->getDefaultValue();
                $folded = $default === null ? null : ScalarFold::of($default);
                $argument = $folded === null ? null : ConstValue::scalar($folded[0]);
            }

            if ($argument !== null) {
                $bindings[$parameter->getName()] = $argument;
            }
        }

        return $bindings;
    }

    /**
     * @return list<ParameterReflection>
     */
    private function parameters(Callee $callee): array
    {
        if (! $this->reflectionProvider->hasClass($callee->class)) {
            return [];
        }
        $class = $this->reflectionProvider->getClass($callee->class);
        if (! $class->hasNativeMethod($callee->method)) {
            return [];
        }

        return $class->getNativeMethod($callee->method)->getVariants()[0]->getParameters();
    }
}
