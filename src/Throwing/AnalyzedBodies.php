<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Analysis\ParamAccessor;
use PhpParser\Node;
use PHPStan\Type\Constant\ConstantIntegerType;

/**
 * The analyser's answer to {@see ClassBodies}: bodies off the file harvest, and folding through the scope
 * the harvest paired with the CALL the expression is an argument of, so a class constant
 * (`Response::HTTP_CONFLICT`) reads the same as the literal beside it, a variable reads as what the
 * callee was actually handed, and an enum case reads as the status its method maps it to.
 *
 * A parameter default comes off the same harvest, out of PHPStan's own reflection of the parsed
 * declaration — the analyser resolves the initialiser to a type without running it, which is the whole
 * reason this answer is here rather than on a `ReflectionParameter`.
 *
 * @internal
 */
final class AnalyzedBodies implements ClassBodies
{
    public function __construct(
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly CaseStatus $cases,
    ) {}

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

    public function foldStatus(string $file, Node\Expr $expr, Node\Expr\New_|Node\Expr\StaticCall|Node\Expr\MethodCall $at, ?ParamAccessor $read): array
    {
        $scope = $this->fileAnalyzer->scopeAtCall($file, $at);

        return $scope === null ? ['status' => null, 'files' => []] : $this->cases->of($expr, $scope, $read);
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
