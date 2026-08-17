<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use BackedEnum;
use Closure;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use ReflectionEnum;
use Throwable;

/**
 * Folds an accessor on a known enum case to a literal — the last hop of the folding arc, driven by
 * {@see ResponseShapeRefiner}. Two containment rules live here: `->value`/`->name` come off the case by
 * reflection, so vendor enums work too (`->value` needs a backed enum, `->name` is universal); `->method()`
 * only folds for a project enum, by analysing one body with `$this` narrowed to the case — a
 * `match ($this)` arm or a plain constant return. Anything computed, or any vendor enum method, folds to
 * null rather than a guess.
 *
 * Memoised per (enum-case, method); the enum's file goes through {@see $recordFile} on every path, hit
 * included, so it reaches `dependencyFiles`.
 *
 * @internal
 */
final class EnumAccessorFolder
{
    /**
     * A folded method accessor is call-independent, so a case+method folded once is reused everywhere.
     *
     * @var array<string, LiteralT|null>
     */
    private array $methodMemo = [];

    /**
     * @param  Closure(string): void  $recordFile  sink that lands a descended file in the analysis's dependency set
     */
    public function __construct(
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly ProjectFilter $projectFilter,
        private readonly Closure $recordFile,
    ) {}

    /**
     * Null when it doesn't fold: a computed body, a vendor enum method, `->value` on a non-backed enum, or an
     * identity read (the case object itself is not a documentable scalar).
     */
    public function fold(string $enumFqcn, string $caseName, ParamAccessor $accessor): ?LiteralT
    {
        return match ($accessor->kind) {
            AccessorKind::Value => $this->backingValue($enumFqcn, $caseName),
            AccessorKind::Name => new LiteralT($caseName),
            AccessorKind::Method => $accessor->method === null ? null : $this->foldMethod($enumFqcn, $caseName, $accessor->method),
            AccessorKind::Identity => null,
        };
    }

    /** By reflection, so vendor-safe; null when the enum isn't backed. */
    private function backingValue(string $enumFqcn, string $caseName): ?LiteralT
    {
        if (! enum_exists($enumFqcn)) {
            return null;
        }

        try {
            $reflection = new ReflectionEnum($enumFqcn);
            if (! $reflection->isBacked() || ! $reflection->hasCase($caseName)) {
                return null;
            }
            // Read the value off the case instance, not ReflectionEnumBackedCase: narrowing UnitEnum to
            // BackedEnum here doesn't depend on how a given PHPStan release stubs getCase()'s return type.
            $case = $reflection->getCase($caseName)->getValue();

            return $case instanceof BackedEnum ? new LiteralT($case->value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Analyses the (project-only) body, picks the `match ($this)` arm for this case — or a plain constant
     * return — and folds it. The enum file is recorded on every path, hit included.
     */
    private function foldMethod(string $enumFqcn, string $caseName, string $method): ?LiteralT
    {
        $key = $enumFqcn.'::'.$caseName.'::'.$method;
        if (array_key_exists($key, $this->methodMemo)) {
            $this->recordDeclaringFile($enumFqcn, $method);

            return $this->methodMemo[$key];
        }

        return $this->methodMemo[$key] = $this->computeMethod($enumFqcn, $caseName, $method);
    }

    private function computeMethod(string $enumFqcn, string $caseName, string $method): ?LiteralT
    {
        $file = $this->declaringFile($enumFqcn, $method);
        if ($file === null || ! $this->projectFilter->isProjectFile($file)) {
            return null; // vendor / unresolved — never analyse a vendor enum's method body
        }

        ($this->recordFile)($file);

        $node = $this->fileAnalyzer->method($file, $enumFqcn, $method);
        if ($node === null) {
            return null;
        }

        foreach ($node->getReturnStatements() as $statement) {
            $expr = $statement->getReturnNode()->expr;
            if ($expr === null) {
                continue;
            }
            $scope = $this->fileAnalyzer->stableScope($statement->getScope());

            if ($expr instanceof Node\Expr\Match_) {
                $body = AccessorExtractor::matchArmBodyForCase(
                    $expr,
                    $enumFqcn,
                    $caseName,
                    static fn (Node\Name $name): string => $scope->resolveName($name),
                );

                return $body === null ? null : $this->constLiteral($body, $scope);
            }

            return $this->constLiteral($expr, $scope);
        }

        return null;
    }

    /** Null when the expression doesn't constant-fold. */
    private function constLiteral(Node\Expr $expr, Scope $scope): ?LiteralT
    {
        $folded = ScalarFold::of($scope->getType($expr));

        return $folded !== null && is_scalar($folded[0]) ? new LiteralT($folded[0]) : null;
    }

    private function recordDeclaringFile(string $enumFqcn, string $method): void
    {
        $file = $this->declaringFile($enumFqcn, $method);
        if ($file !== null && $this->projectFilter->isProjectFile($file)) {
            ($this->recordFile)($file);
        }
    }

    /** By native reflection; null when unresolvable. */
    private function declaringFile(string $enumFqcn, string $method): ?string
    {
        if (! enum_exists($enumFqcn)) {
            return null;
        }

        try {
            $reflection = new ReflectionEnum($enumFqcn);
            if (! $reflection->hasMethod($method)) {
                return null;
            }
            $file = $reflection->getMethod($method)->getFileName();

            return $file === false ? null : $file;
        } catch (Throwable) {
            return null;
        }
    }
}
