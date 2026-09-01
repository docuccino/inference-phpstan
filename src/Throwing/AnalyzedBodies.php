<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use PhpParser\Node;
use PHPStan\Type\Constant\ConstantIntegerType;

/**
 * The analyser's answer to {@see ClassBodies}: bodies off the file harvest, and folding through the scope
 * the harvest paired with the CALL the expression is an argument of, so a class constant
 * (`Response::HTTP_CONFLICT`) reads the same as the literal beside it and a variable reads as what the
 * callee was actually handed.
 *
 * A parameter default comes off the same harvest, out of PHPStan's own reflection of the parsed
 * declaration — the analyser resolves the initialiser to a type without running it, which is the whole
 * reason this answer is here rather than on a `ReflectionParameter`.
 *
 * @internal
 */
final class AnalyzedBodies implements ClassBodies
{
    public function __construct(private readonly FileAnalyzer $fileAnalyzer) {}

    public function methods(string $file, string $class): array
    {
        $prefix = $class.'::';

        $methods = [];
        foreach ($this->fileAnalyzer->analyze($file) as $key => $method) {
            if (str_starts_with($key, $prefix)) {
                $methods[$method->getMethodName()] = $method->getStatements();
            }
        }

        return $methods;
    }

    public function foldInt(string $file, Node\Expr $expr, Node\Expr\New_|Node\Expr\StaticCall $at): ?int
    {
        $scope = $this->fileAnalyzer->scopeAtCall($file, $at);
        if ($scope === null) {
            return null;
        }

        $type = $scope->getType($expr);

        return $type instanceof ConstantIntegerType ? $type->getValue() : null;
    }

    public function intDefault(string $file, string $class, string $method, int $index): ?int
    {
        $body = $this->fileAnalyzer->method($file, $class, $method);
        if ($body === null) {
            return null;
        }

        $parameter = $body->getMethodReflection()->getVariants()[0]->getParameters()[$index] ?? null;
        $default = $parameter?->getDefaultValue();

        return $default instanceof ConstantIntegerType ? $default->getValue() : null;
    }
}
