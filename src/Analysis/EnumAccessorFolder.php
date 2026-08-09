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
 * ROLE: folds an accessor on a KNOWN enum case to a literal — the final hop of the folding arc
 * (`inference-embedding.md` §4a step 3, the canonical account; {@see ResponseShapeRefiner} drives it).
 *
 * The two containment rules this class OWNS, since they are enforced here:
 *   - `->value` / `->name` fold from the case by reflection, so they work for VENDOR enums too (no body
 *     is analysed). `->value` needs a backed enum; `->name` is universal.
 *   - `->method()` folds only for a PROJECT enum, by analysing ONE method body with `$this` narrowed to
 *     the bound case: a `match ($this)` arm naming the case, or a plain constant return. Anything else —
 *     a computed expression, a translation call, a vendor enum — folds to null, never a guess.
 *
 * Memoised per (enum-case, method); the enum's file is reported through the {@see $recordFile} sink on
 * every path (miss AND hit) so it lands in `dependencyFiles`.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class EnumAccessorFolder
{
    /**
     * Per (enum-case, method) memo of a folded method accessor — call-independent, so a case+method
     * folded once is reused across every route that reaches it.
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
     * The literal an accessor on `$enumFqcn::$caseName` folds to, or null when it does not fold (a
     * computed method body, a vendor enum method, a `->value` on a non-backed enum, or an identity read
     * of the case object itself — an enum object is not a documentable scalar).
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

    /** The backed value of a case as a literal (reflection; vendor-safe), or null when not a backed enum. */
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
            // Read the backing value off the case INSTANCE rather than through
            // ReflectionEnumBackedCase: `isBacked()` is the invariant that matters, and narrowing
            // UnitEnum to BackedEnum does not depend on how a given PHPStan release stubs the
            // return type of ReflectionEnum::getCase().
            $case = $reflection->getCase($caseName)->getValue();

            return $case instanceof BackedEnum ? new LiteralT($case->value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fold a no-arg accessor method on the bound case: analyse the (project-only) method body, select the
     * `match ($this)` arm for this case (or a plain constant return), and fold its constant body. Memoised
     * per (enum-case, method); the enum file lands in the dependency set on every path (miss and hit).
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

        $node = $this->fileAnalyzer->analyze($file)[$method] ?? null;
        if ($node === null) {
            return null;
        }

        foreach ($node->getReturnStatements() as $statement) {
            $expr = $statement->getReturnNode()->expr;
            if ($expr === null) {
                continue;
            }
            $scope = $statement->getScope();

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

    /** A single constant scalar expression as a literal, or null when it does not constant-fold. */
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

    /** The file the enum method is declared in (native reflection), or null when unresolvable. */
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
