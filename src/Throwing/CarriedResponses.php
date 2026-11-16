<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Analysis\ParentArgument;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ReflectionClass;
use ReflectionParameter;

/**
 * The response an `HttpResponseException` carries for the framework to send as it is, read off the construction
 * a `throw` makes: the argument that lands in the framework constructor's `$response`, through every
 * `parent::__construct()` an application's subclass makes on the way ({@see ParentArgument}), with the scope it
 * is written in. A static factory on the class is followed to each `new` it makes of it, as a status is
 * ({@see FactoryStatus}). A construction that lands nothing readable answers nothing, and the throw stays the
 * exception it is.
 *
 * @phpstan-type Carried array{responses: list<array{Node\Expr, Scope}>, files: list<string>}
 *
 * @internal
 */
final class CarriedResponses
{
    public const EXCEPTION = 'Illuminate\\Http\\Exceptions\\HttpResponseException';

    public function __construct(
        private readonly ParentArgument $arguments,
        private readonly ClassBodies $bodies,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly ProjectFilter $appFilter,
    ) {}

    /**
     * The response `new $fqcn(…)` carries, typed in `$scope`, which is where the construction is written.
     *
     * @return array{Node\Expr, Scope}|null
     */
    public function constructed(Node\Expr\New_ $construction, string $fqcn, Scope $scope): ?array
    {
        $landing = $this->arguments->response($fqcn, self::EXCEPTION);
        if ($landing === null) {
            return null;
        }

        if (isset($landing['expr'])) {
            $at = $this->fileAnalyzer->scopeAtCall($landing['file'], $landing['call']);

            return $at === null ? null : [$landing['expr'], $at];
        }

        $argument = $construction->isFirstClassCallable()
            ? null
            : ArgumentSlots::of($construction->getArgs(), self::parameterNames($fqcn))->at($landing['parameter']);

        return $argument === null ? null : [$argument, $scope];
    }

    /**
     * Every response `$fqcn::$method()` can carry, one per construction its body makes — none where one of them
     * lands nothing readable, or the factory is not the application's own. The files are the factory's, which
     * the answer depends on either way.
     *
     * @return Carried
     */
    public function fromFactory(string $fqcn, string $method): array
    {
        $class = class_exists($fqcn) ? new ReflectionClass($fqcn) : null;
        $factory = $class !== null && $class->hasMethod($method) ? $class->getMethod($method) : null;
        $file = $factory === null ? false : $factory->getFileName();
        if ($factory === null || $file === false || ! $factory->isStatic() || ! $this->appFilter->isProjectFile($file)) {
            return ['responses' => [], 'files' => []];
        }

        $declaring = $factory->getDeclaringClass()->getName();
        $responses = [];
        foreach (StatusForwarding::constructionsOf($this->bodies->methods($file, $declaring)[$method] ?? [], $fqcn, $declaring) as $construction) {
            $scope = $this->fileAnalyzer->scopeAtCall($file, $construction);
            $carried = $scope === null ? null : $this->constructed($construction, $fqcn, $scope);
            if ($carried === null) {
                return ['responses' => [], 'files' => [$file]];
            }

            $responses[] = $carried;
        }

        return ['responses' => $responses, 'files' => [$file]];
    }

    /** @return list<string> */
    private static function parameterNames(string $fqcn): array
    {
        $constructor = class_exists($fqcn) ? (new ReflectionClass($fqcn))->getConstructor() : null;

        return array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor?->getParameters() ?? []);
    }
}
