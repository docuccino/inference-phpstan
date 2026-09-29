<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Node\Expr\PropertyInitializationExpr;
use PHPStan\Type\NeverType;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Whether the constructor a class runs assigns a typed property on every path that completes — what decides
 * whether `json_encode` writes its key. Read off the analyser's tracking of property initialisation at each
 * end of the constructor, so a branch, an early `return` or a never-returning call is judged as PHP runs it,
 * and a `$this->method()` the class declares is followed as the analyser follows it. A path that skips the
 * property is a proof only where nothing the analyser does not follow takes part in building the object
 * ({@see ConstructionEscape}); anything else is left unanswered.
 *
 * @internal
 */
final class ConstructorInitialisation
{
    public function __construct(
        private readonly FileAnalyzer $files,
        private readonly ConstructionEscape $escape = new ConstructionEscape,
    ) {}

    /**
     * True where every completing path assigns it, false where one completes without it, null where the
     * property needs no answer (untyped, defaulted, promoted) or the analysis cannot prove either.
     *
     * @param  ReflectionClass<object>  $class
     */
    public function of(ReflectionClass $class, ReflectionProperty $property): ?bool
    {
        $constructor = $class->getConstructor();
        if (! $property->hasType()
            || $property->hasDefaultValue()
            || $property->isPromoted()
            || $constructor === null
            || $constructor->getDeclaringClass()->getName() !== $property->getDeclaringClass()->getName()
        ) {
            return null;
        }

        $file = $constructor->getFileName();
        if ($file === false) {
            return null;
        }

        try {
            $declaring = $constructor->getDeclaringClass()->getName();
            $body = $this->files->method($file, $declaring, '__construct');
            if ($body === null || $body->getClassReflection()->getName() !== $declaring) {
                return null;
            }

            $ends = [];
            foreach ($body->getExecutionEnds() as $end) {
                $result = $end->getStatementResult();
                $node = $end->getNode();
                // A statement that throws or calls a never-returning function ends no construction.
                if ($result->isAlwaysTerminating() && $node instanceof Expression) {
                    $type = $this->files->stableScope($result->getScope())->getType($node->expr);
                    if ($type instanceof NeverType && $type->isExplicit()) {
                        continue;
                    }
                }
                $ends[] = $result->getScope();
            }
            foreach ($body->getReturnStatements() as $return) {
                $ends[] = $return->getScope();
            }

            // No completing path seen — a constructor that always throws, or a body the analyser was not given.
            if ($ends === []) {
                return null;
            }

            // The analyser's marker for "assigned by now"; no API names it. AnalyserDriftTest guards the name.
            // @phpstan-ignore phpstanApi.constructor
            $assigned = new PropertyInitializationExpr($property->getName());
            foreach ($ends as $scope) {
                if (! $this->files->stableScope($scope)->hasExpressionType($assigned)->yes()) {
                    return $this->escape->possible($class, $property->getName(), $body->getStatements()) ? null : false;
                }
            }

            return true;
        } catch (Throwable) {
            return null;
        }
    }
}
