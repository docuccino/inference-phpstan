<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

/**
 * Recovers the real response shape when a handler builds its response through a project helper whose
 * declared return type erases it (`renderNotFound(): JsonResponse`): follows the call into the callee's
 * own return sites and substitutes the richer `JsonResponse<payload, status, contentType>`. Design
 * detail: see docs/design/inference-embedding.md §4a.
 *
 * Invariants: bounded by the engine's descent depth and per-analysis file budget; memoised per callee
 * `class::method` but only when the descent completed (a budget-truncated result is used once and not
 * cached, so route order can't change the final output); a callee's shape is call-independent, so
 * statuses and body members that read a parameter are recorded as accessors and bound at the call site;
 * nothing is ever guessed — an unfoldable status stays permissive; vendor code is never followed —
 * containment is the PRIME scope (every app PSR-4 root, including a modular `Modules\…` one), not the
 * narrower descend scope throws/QB-trace use; every file touched is reported via {@see takeFiles()} so
 * the fragment cache stays sound.
 *
 * @internal
 */
final class ResponseShapeRefiner
{
    /** Bare (generic-erased) response classes a helper's declared return type collapses to. */
    private const RESPONSE_FQCNS = [
        'Illuminate\\Http\\JsonResponse',
        'Illuminate\\Http\\Response',
        'Symfony\\Component\\HttpFoundation\\JsonResponse',
        'Symfony\\Component\\HttpFoundation\\Response',
    ];

    /** The canonical FQCN the recovered shape is emitted under (the shape the pipeline unwraps). */
    public const CANONICAL_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    /**
     * Per-callee shape + the files its descent touched. The files are re-contributed on a memo hit, so a
     * second route reaching the same helper still records the dependency.
     *
     * @var array<string, array{result: RefinedResponse|null, files: list<string>}>
     */
    private array $memo = [];

    /** @var array<string, true> cycle guard over the descent (callee `class::method`). */
    private array $inProgress = [];

    /**
     * Depth/file-budget cutoffs so far. A callee whose computation bumped this was truncated and must not
     * be memoised ({@see refineCallee()}); a cycle decline is deterministic, so it doesn't bump it.
     */
    private int $budgetCutoffs = 0;

    /** @var array<string, true> files touched by the current analysis, drained by {@see takeFiles()}. */
    private array $currentFiles = [];

    /** Folds accessors on a bound enum case (`->value`, `->name`, `->status()`) — the last hop. */
    private readonly EnumAccessorFolder $enumFolder;

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly TypeTranslator $translator,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly CalleeResolver $calleeResolver,
        private readonly ProjectFilter $projectFilter,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly int $maxDepth = 4,
        private readonly int $fileBudget = 40,
    ) {
        $this->enumFolder = new EnumAccessorFolder(
            $this->fileAnalyzer,
            $this->projectFilter,
            function (string $file): void {
                $this->currentFiles[$this->adapter->normalize($file)] = true;
            },
        );
    }

    /** Whether a FQCN is a bare response type worth enriching — the harvest's gate before descending. */
    public static function isResponseFqcn(string $fqcn): bool
    {
        return in_array($fqcn, self::RESPONSE_FQCNS, true);
    }

    /** Null when nothing better than the bare type is recoverable. */
    public function refine(Node\Expr $expr, Scope $scope): ?RefinedResponse
    {
        return $this->refineExpr($expr, $scope, [], 0);
    }

    /**
     * Files touched since the last drain, for the analysis's `dependencyFiles`. Draining resets the
     * per-analysis set; the memo and its file sets outlive it.
     *
     * @return list<string>
     */
    public function takeFiles(): array
    {
        $files = array_keys($this->currentFiles);
        sort($files);
        $this->currentFiles = [];

        return $files;
    }

    /**
     * @param  list<string>  $paramNames  the current function's parameter names — a status expression
     *                                    that is one of these is a pass-through the caller can bind.
     */
    private function refineExpr(Node\Expr $expr, Scope $scope, array $paramNames, int $depth): ?RefinedResponse
    {
        // 1. `new JsonResponse($body, $status, [headers])` — fold the constructor arguments directly.
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $class = $scope->resolveName($expr->class);
            if (self::isResponseFqcn($class)) {
                return $this->foldConstructor($expr, $scope, $paramNames);
            }
        }

        // 2. Type system already carries the shape (`response()->json([...], 422)`, via our extension).
        $type = $this->translator->translate($scope->getType($expr));
        if ($type instanceof ClassT && self::isResponseFqcn($type->fqcn) && $type->typeArgs !== []) {
            return $this->fromTypeArgs($type);
        }

        // 3. A call into project code whose declared return erased the shape — descend and substitute.
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) {
            if ($type instanceof ClassT && self::isResponseFqcn($type->fqcn)) {
                if ($depth >= $this->maxDepth) {
                    $this->budgetCutoffs++; // depth cutoff — the enclosing shape is truncated

                    return null;
                }
                $callee = $this->calleeResolver->resolve($expr, $scope);
                if ($callee !== null && $this->projectFilter->isProjectFile($callee->file)) {
                    if (! $this->withinBudget($callee->file)) {
                        $this->budgetCutoffs++; // file-budget cutoff — likewise a truncation

                        return null;
                    }
                    $child = $this->refineCallee($callee, $depth + 1);
                    if ($child === null || $child->delegates) {
                        return $child;
                    }

                    return $this->bindCall($child, $callee, $expr, $scope, $paramNames);
                }
            }

            return null; // vendor / unresolvable — a deterministic decline, not a truncation
        }

        // 4. A `return null` / void arm — the renderer delegates this type to the framework.
        if ($type instanceof NullT || $type instanceof VoidT) {
            return RefinedResponse::delegation();
        }

        return null;
    }

    /**
     * Fold `new JsonResponse($body, $status, [headers])`: payload from arg 0, status from arg 1 (literal,
     * pass-through parameter, or permissive), content type from a `Content-Type` header in arg 2.
     * Symfony's constructor defaults an omitted status to 200.
     *
     * @param  list<string>  $paramNames
     */
    private function foldConstructor(Node\Expr\New_ $new, Scope $scope, array $paramNames): RefinedResponse
    {
        $args = $new->getArgs();

        $payload = null;
        $provenance = [];
        if (isset($args[0])) {
            $payload = $this->payloadOf($scope->getType($args[0]->value));
            $provenance = $this->payloadProvenance($args[0]->value, $scope, $paramNames);
        }

        [$status, $statusSource] = isset($args[1])
            ? $this->resolveStatus($args[1]->value, $scope, $paramNames)
            : [new LiteralT(200), null];

        $contentType = isset($args[2]) ? $this->contentTypeOf($args[2]->value, $scope) : null;

        // A member reading the same accessor as the status echoes the status: the factory marks it, so a
        // call site folding the status folds the member too, and an unfolded one still fills at doc time.
        return RefinedResponse::fromConstructor($payload, $status, $statusSource, $contentType, $provenance);
    }

    /**
     * Which body members read off a parameter, so a call site can fold them once it knows the argument.
     *
     * @param  list<string>  $paramNames
     * @return array<string, ParamAccessor> member key → the accessor its value reads from
     */
    private function payloadProvenance(Node\Expr $expr, Scope $scope, array $paramNames): array
    {
        $array = $this->bodyArrayLiteral($expr, $scope);

        return $array === null ? [] : AccessorExtractor::provenanceFromArray($array, $paramNames);
    }

    /**
     * The expression itself when the body is inline, or the initialiser of the local it was built up in.
     * Null just means no provenance — the shape still comes from PHPStan.
     */
    private function bodyArrayLiteral(Node\Expr $expr, Scope $scope): ?Node\Expr\Array_
    {
        if ($expr instanceof Node\Expr\Array_) {
            return $expr;
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $method = $scope->getFunctionName();
            if ($method !== null) {
                return $this->fileAnalyzer->arrayAssignments($scope->getFile())[$method][$expr->name] ?? null;
            }
        }

        return null;
    }

    /**
     * Build from an already-resolved `JsonResponse<payload, status, contentType>` generic — the
     * `response()->json()` extension emits the first two args, our own descent may carry the third.
     */
    private function fromTypeArgs(ClassT $type): RefinedResponse
    {
        // A void payload (`noContent()`) is a real "no body", not an unfolded one; only UnknownT is absent.
        $payloadArg = $type->typeArgs[0] ?? null;
        $payload = $payloadArg instanceof UnknownT ? null : $payloadArg;
        $statusArg = $type->typeArgs[1] ?? null;
        $status = $statusArg instanceof LiteralT && is_int($statusArg->value) ? $statusArg : null;
        $ctArg = $type->typeArgs[2] ?? null;
        $contentType = $ctArg instanceof LiteralT && is_string($ctArg->value) ? $ctArg->value : null;

        return new RefinedResponse($payload, $status, null, $contentType);
    }

    /**
     * The call-independent shape of a project callee: analyse its return sites (bounded, memoised,
     * cycle-guarded) and fold the first documentable — or delegating — one. Callers gate on
     * {@see ProjectFilter}, so vendor callees never reach here.
     */
    private function refineCallee(Callee $callee, int $depth): ?RefinedResponse
    {
        $key = $callee->class.'::'.$callee->method;

        if (array_key_exists($key, $this->memo)) {
            foreach ($this->memo[$key]['files'] as $file) {
                $this->currentFiles[$file] = true;
            }

            return $this->memo[$key]['result'];
        }

        if (isset($this->inProgress[$key])) {
            return null; // cycle — deterministic, so not memoised and not a truncation
        }
        if ($depth > $this->maxDepth || ! $this->withinBudget($callee->file)) {
            $this->budgetCutoffs++; // a truncation

            return null; // over-budget — declined, and not memoised
        }

        $this->inProgress[$key] = true;
        $filesBefore = $this->currentFiles;
        $cutoffsBefore = $this->budgetCutoffs;
        $result = $this->computeCalleeShape($callee, $depth);
        unset($this->inProgress[$key]);

        // Memoise only a descent that stayed within budget/depth. A truncated one (here or deeper down) is
        // less refined depending on how much budget was already spent before this callee was reached —
        // caching it would make output route-/worker-order dependent. Used now, recomputed next time.
        if ($this->budgetCutoffs === $cutoffsBefore) {
            $delta = array_keys(array_diff_key($this->currentFiles, $filesBefore));
            sort($delta);
            $this->memo[$key] = ['result' => $result, 'files' => $delta];
        }

        return $result;
    }

    private function computeCalleeShape(Callee $callee, int $depth): ?RefinedResponse
    {
        $this->currentFiles[$this->adapter->normalize($callee->file)] = true;

        $node = $this->fileAnalyzer->analyze($callee->file)[$callee->method] ?? null;
        if ($node === null) {
            return null;
        }

        $paramNames = $this->parameterNames($callee);

        $delegation = null;
        foreach ($node->getReturnStatements() as $statement) {
            $expr = $statement->getReturnNode()->expr;
            if ($expr === null) {
                $delegation ??= RefinedResponse::delegation();

                continue;
            }

            $refined = $this->refineExpr($expr, $statement->getScope(), $paramNames, $depth);
            if ($refined === null) {
                continue;
            }
            if ($refined->delegates) {
                $delegation ??= $refined;

                continue;
            }

            return $refined; // first documentable return wins (a helper's single response)
        }

        return $delegation;
    }

    /**
     * Payload binding runs first so a status member folds consistently with the HTTP status — both key on
     * the same argument.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function bindCall(RefinedResponse $child, Callee $callee, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        return $this->bindStatus(
            $this->bindPayload($child, $callee, $call, $scope, $paramNames),
            $callee,
            $call,
            $scope,
            $paramNames,
        );
    }

    /**
     * A foldable argument resolves the status outright — an int literal, or a concrete enum case whose
     * accessor folds (`make(ProblemType::Forbidden, …)` → `$problem->status()` → 403); a caller parameter
     * re-homes the accessor one hop out; anything else stays permissive.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function bindStatus(RefinedResponse $child, Callee $callee, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        $source = $child->statusSource;
        if ($source === null) {
            return $child;
        }

        $argExpr = $this->argumentFor($callee, $source->param, $call);
        if ($argExpr === null) {
            return $child->withStatusSource(null);
        }

        $literal = $this->foldAccessorArgument($argExpr, $source, $scope);
        if ($literal !== null && is_int($literal->value)) {
            return $child->withBoundStatus($literal);
        }

        $rehome = $this->rehomeAccessor($argExpr, $source, $paramNames);

        return $rehome === null ? $child->withStatusSource(null) : $child->withStatusSource($rehome);
    }

    /**
     * A constant-foldable argument pins the member to that literal; a caller parameter re-homes the
     * provenance one hop out; anything else drops it and leaves the member widened (a {@see StatusMarkerT}
     * member is left for the response seam). A member is only ever pinned to a value that flows to it.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function bindPayload(RefinedResponse $child, Callee $callee, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        if ($child->payloadParamProvenance === [] || ! $child->payload instanceof ArrayShapeT) {
            return $child;
        }

        // Classify each member's forwarded argument, then let RefinedResponse apply the pure rewrite.
        foreach ($child->payloadParamProvenance as $key => $accessor) {
            $argExpr = $this->argumentFor($callee, $accessor->param, $call);
            if ($argExpr === null) {
                $child = $child->bindMember($key, null, null);

                continue;
            }

            $literal = $this->foldAccessorArgument($argExpr, $accessor, $scope);
            $rehome = $literal === null ? $this->rehomeAccessor($argExpr, $accessor, $paramNames) : null;

            $child = $child->bindMember($key, $literal, $rehome);
        }

        return $child;
    }

    /**
     * An identity accessor folds a constant-scalar argument directly; an enum accessor folds only when the
     * argument is a concrete enum case, via {@see EnumAccessorFolder}. Null when nothing folds.
     */
    private function foldAccessorArgument(Node\Expr $argExpr, ParamAccessor $accessor, Scope $scope): ?LiteralT
    {
        if ($accessor->kind === AccessorKind::Identity) {
            return $this->constLiteralOf($argExpr, $scope);
        }

        $case = $this->enumCaseOf($argExpr, $scope);

        return $case === null ? null : $this->enumFolder->fold($case['fqcn'], $case['case'], $accessor);
    }

    /**
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function rehomeAccessor(Node\Expr $argExpr, ParamAccessor $accessor, array $paramNames): ?ParamAccessor
    {
        return AccessorExtractor::rehome($argExpr, $accessor, $paramNames);
    }

    /**
     * Handles both a written `ProblemType::Forbidden` and a variable PHPStan narrowed to one case; null
     * when it isn't a single known case.
     *
     * @return array{fqcn: string, case: string}|null
     */
    private function enumCaseOf(Node\Expr $expr, Scope $scope): ?array
    {
        $fromConst = AccessorExtractor::enumCaseFromConstFetch($expr, static fn (Node\Name $name): string => $scope->resolveName($name));
        if ($fromConst !== null) {
            return $fromConst;
        }

        $cases = $scope->getType($expr)->getEnumCases();

        return count($cases) === 1 ? ['fqcn' => $cases[0]->getClassName(), 'case' => $cases[0]->getEnumCaseName()] : null;
    }

    /**
     * A status expression as either a call-independent literal int, the {@see ParamAccessor} it reads from
     * (a parameter, or an accessor on an enum parameter like `$problem->status()`), or neither.
     *
     * @param  list<string>  $paramNames
     * @return array{?LiteralT, ?ParamAccessor}
     */
    private function resolveStatus(Node\Expr $expr, Scope $scope, array $paramNames): array
    {
        $literal = $this->intLiteralOf($expr, $scope);
        if ($literal !== null) {
            return [new LiteralT($literal), null];
        }

        return [null, AccessorExtractor::fromExpr($expr, $paramNames)];
    }

    /** The int-only specialisation of {@see ScalarFold}. */
    private function intLiteralOf(Node\Expr $expr, Scope $scope): ?int
    {
        $folded = ScalarFold::of($scope->getType($expr));

        return $folded !== null && is_int($folded[0]) ? $folded[0] : null;
    }

    /**
     * Null when the argument doesn't fold to a single scalar — a folded `null` isn't a documentable literal.
     */
    private function constLiteralOf(Node\Expr $expr, Scope $scope): ?LiteralT
    {
        $folded = ScalarFold::of($scope->getType($expr));

        return $folded !== null && is_scalar($folded[0]) ? new LiteralT($folded[0]) : null;
    }

    /** Matched case-insensitively; null when absent or non-constant. */
    private function contentTypeOf(Node\Expr $expr, Scope $scope): ?string
    {
        if (! $expr instanceof Node\Expr\Array_) {
            return null;
        }

        foreach ($expr->items as $item) {
            if ($item->key === null) {
                continue;
            }
            $keys = $scope->getType($item->key)->getConstantStrings();
            if (count($keys) !== 1 || strcasecmp($keys[0]->getValue(), 'content-type') !== 0) {
                continue;
            }
            $values = $scope->getType($item->value)->getConstantStrings();
            if (count($values) === 1) {
                return $values[0]->getValue();
            }
        }

        return null;
    }

    /** The payload DType, or null when it is not a documentable body (void/never/unknown). */
    private function payloadOf(Type $type): ?DType
    {
        return $this->documentablePayload($this->translator->translate($type));
    }

    private function documentablePayload(?DType $payload): ?DType
    {
        if ($payload === null || $payload instanceof VoidT || $payload instanceof NeverT || $payload instanceof UnknownT) {
            return null;
        }

        return $payload;
    }

    /** A named argument if present, otherwise the positional one at that parameter's index. */
    private function argumentFor(Callee $callee, string $paramName, Node\Expr $call): ?Node\Expr
    {
        if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
            return null;
        }

        $params = $this->parameterNames($callee);
        $index = array_search($paramName, $params, true);

        $positional = [];
        foreach ($call->getArgs() as $arg) {
            if ($arg->name instanceof Node\Identifier) {
                if ($arg->name->toString() === $paramName) {
                    return $arg->value;
                }

                continue;
            }
            $positional[] = $arg->value;
        }

        return $index !== false && isset($positional[$index]) ? $positional[$index] : null;
    }

    /**
     * In declaration order, for positional binding.
     *
     * @return list<string>
     */
    private function parameterNames(Callee $callee): array
    {
        if (! $this->reflectionProvider->hasClass($callee->class)) {
            return [];
        }
        $class = $this->reflectionProvider->getClass($callee->class);
        if (! $class->hasNativeMethod($callee->method)) {
            return [];
        }

        $names = [];
        foreach ($class->getNativeMethod($callee->method)->getVariants()[0]->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }

    private function withinBudget(string $file): bool
    {
        return count($this->currentFiles) < $this->fileBudget
            || isset($this->currentFiles[$this->adapter->normalize($file)]);
    }
}
