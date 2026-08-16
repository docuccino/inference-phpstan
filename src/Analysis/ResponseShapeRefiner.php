<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\DType\ArrayShapeField;
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
 * own return sites and substitutes the richer `JsonResponse<payload, status, contentType, members>`. Design
 * detail: see docs/design/inference-embedding.md §4a.
 *
 * Invariants: bounded by the engine's descent depth and per-analysis file budget; memoised per callee
 * `class::method`, with the memo bound-aware in BOTH directions — a truncated result is used once and
 * never cached, and a complete entry is only served to a caller with the depth and file budget to have
 * computed it itself, so a route's shape never depends on which unrelated route ran first; a callee's
 * shape is call-independent, so statuses and body members that read a parameter are recorded as accessors
 * and bound at the call site; nothing is ever guessed — an unfoldable status stays permissive, and a
 * descent that ran out of bound says so via {@see takeTruncations()}; vendor code is never followed —
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

    /** Memo + bound accounting: what a descent cost, and whether a caller can afford to be served it. */
    private readonly DescentBudget $budget;

    /** Folds accessors on a bound enum case (`->value`, `->name`, `->status()`) — the last hop. */
    private readonly EnumAccessorFolder $enumFolder;

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly TypeTranslator $translator,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly CalleeResolver $calleeResolver,
        private readonly ProjectFilter $projectFilter,
        private readonly ReflectionProvider $reflectionProvider,
        int $maxDepth = 4,
        int $fileBudget = 40,
    ) {
        $this->budget = new DescentBudget($maxDepth, $fileBudget);
        $this->enumFolder = new EnumAccessorFolder(
            $this->fileAnalyzer,
            $this->projectFilter,
            function (string $file): void {
                $this->touch($file);
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
        return $this->budget->takeFiles();
    }

    /**
     * How many times descent stopped at a depth/file bound since the last drain. A response body that
     * quietly lost its shape is a silent degradation, so the analysis says so; the count is a function of
     * the analysis alone, since the memo only ever answers what the caller could have computed.
     */
    public function takeTruncations(): int
    {
        return $this->budget->takeTruncations();
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
                if (! $this->budget->withinDepth($depth + 1)) {
                    $this->budget->truncate(); // depth cutoff — the enclosing shape is truncated

                    return null;
                }
                $callee = $this->calleeResolver->resolve($expr, $scope);
                if ($callee !== null && $this->projectFilter->isProjectFile($callee->file)) {
                    if (! $this->budget->withinBudget($this->adapter->normalize($callee->file))) {
                        $this->budget->truncate(); // file-budget cutoff — likewise a truncation

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

        // No member map to read back: this reads a PHPStan type, and the map is written past the last
        // `@template` the stub declares, so only our own descent ever carries one.
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

        // A memoised shape the caller has the headroom to have computed itself — anything else is
        // recomputed, and truncates honestly if the bound is genuinely spent.
        $replayed = $this->budget->replay($key, $depth);
        if ($replayed !== null) {
            return $replayed[0];
        }

        if ($this->budget->isDescending($key)) {
            return null; // cycle — deterministic, so not memoised and not a truncation
        }
        if (! $this->budget->withinDepth($depth) || ! $this->budget->withinBudget($this->adapter->normalize($callee->file))) {
            $this->budget->truncate();

            return null; // over-budget — declined, and not memoised
        }

        $frame = $this->budget->open($key, $depth);
        $result = $this->computeCalleeShape($callee, $depth);
        $this->budget->close($key, $frame, $result);

        return $result;
    }

    /** Normalise before it reaches the budget, which counts files by their canonical path. */
    private function touch(string $file): void
    {
        $this->budget->touch($this->adapter->normalize($file));
    }

    private function computeCalleeShape(Callee $callee, int $depth): ?RefinedResponse
    {
        $this->touch($callee->file);

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

            $refined = $this->refineExpr($expr, $this->fileAnalyzer->stableScope($statement->getScope()), $paramNames, $depth);
            if ($refined === null) {
                continue;
            }
            if ($refined->delegates) {
                $delegation ??= $refined;

                continue;
            }

            // first documentable return wins (a helper's single response)
            return $refined->contentType === null
                ? self::labelledContentType($refined, $expr, $node->getStatements())
                : $refined;
        }

        return $delegation;
    }

    /**
     * The media type read off a `Content-Type` header write on the returned variable — see
     * {@see ContentTypeLabel} for the window that keeps one branch's label off another branch's body.
     *
     * @param  array<Node\Stmt>  $statements
     */
    private static function labelledContentType(RefinedResponse $refined, Node\Expr $returned, array $statements): RefinedResponse
    {
        $label = ContentTypeLabel::of($statements, $returned);

        return $label === null ? $refined : $refined->withContentType($label);
    }

    /**
     * Payload binding runs first so a status member folds consistently with the HTTP status — both key on
     * the same argument.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function bindCall(RefinedResponse $child, Callee $callee, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        $bound = $this->bindStatus(
            $this->bindPayload($child, $callee, $call, $scope, $paramNames),
            $callee,
            $call,
            $scope,
            $paramNames,
        );

        // Discovery runs after binding, so a member map that arrived from deeper down is bound against THIS
        // call's arguments before a fresh one could be read off the same expression.
        return $this->discoverPayloadMembers($bound, $call, $scope, $paramNames);
    }

    /**
     * The constructor arguments an object payload was built with, when the object is built on the way into
     * the response-producing call — `(new Problem(status: 503, …))->toResponse($request)`, or one project hop
     * away through a factory that returns it (`Problem::make($type, $detail)->toResponse($request)`).
     *
     * Only the arguments actually written are recorded; each is folded here if it can be, and otherwise left
     * with the accessor {@see bindPayload()} binds one hop out. One hop is deliberate: a factory chain deeper
     * than that is a guess about which `new` produced the object.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function discoverPayloadMembers(RefinedResponse $child, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        $payload = $child->payload;
        if ($child->payloadMembers !== null || ! $payload instanceof ClassT || ! $call instanceof Node\Expr\MethodCall) {
            return $child;
        }

        $receiver = $call->var;

        if ($receiver instanceof Node\Expr\New_) {
            [$members, $provenance] = $this->constructedMembers($receiver, $payload->fqcn, $scope, $paramNames);

            return $members === null ? $child : $child->withPayloadMembers($members, $provenance);
        }

        return $this->membersThroughFactory($child, $receiver, $payload->fqcn, $scope, $paramNames);
    }

    /**
     * A project factory returning the payload object: read the `new` in its body, then bind the members it
     * described in the factory's own parameter space onto the arguments this call passed it.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function membersThroughFactory(RefinedResponse $child, Node\Expr $receiver, string $fqcn, Scope $scope, array $paramNames): RefinedResponse
    {
        if (! $receiver instanceof Node\Expr\MethodCall && ! $receiver instanceof Node\Expr\StaticCall) {
            return $child;
        }

        $factory = $this->calleeResolver->resolve($receiver, $scope);
        if ($factory === null || ! $this->projectFilter->isProjectFile($factory->file)) {
            return $child; // vendor / unresolvable — a deterministic decline
        }
        if (! $this->budget->withinBudget($this->adapter->normalize($factory->file))) {
            $this->budget->truncate(); // file-budget cutoff — the enclosing shape is truncated

            return $child;
        }

        $this->touch($factory->file);
        $node = $this->fileAnalyzer->analyze($factory->file)[$factory->method] ?? null;
        if ($node === null) {
            return $child;
        }

        $factoryParams = $this->parameterNames($factory);

        foreach ($node->getReturnStatements() as $statement) {
            $expr = $statement->getReturnNode()->expr;
            if (! $expr instanceof Node\Expr\New_) {
                continue;
            }

            [$members, $provenance] = $this->constructedMembers(
                $expr,
                $fqcn,
                $this->fileAnalyzer->stableScope($statement->getScope()),
                $factoryParams,
            );
            if ($members === null) {
                continue;
            }

            return $this->bindPayload(
                $child->withPayloadMembers($members, $provenance),
                $factory,
                $receiver,
                $scope,
                $paramNames,
            );
        }

        return $child;
    }

    /**
     * One field per supplied constructor argument — the folded literal when it folds, an {@see UnknownT}
     * otherwise — plus the provenance of the unfolded ones. Both null when the `new` isn't the payload class.
     *
     * "Supplied" is what the map means to everything downstream, so an argument written as `X ?? Y`
     * ({@see AccessorExtractor::isConditional()}) only earns a field once something can settle which side
     * renders: a left side rooted in a parameter leaves an accessor here and {@see bindPayload()} settles it
     * one hop out. A left side rooted in anything else — a static trace-id read, a property — is settled
     * nowhere, and a member that is there on some runs and absent on others must not be recorded as one this
     * response carries.
     *
     * @param  list<string>  $paramNames  the parameter names visible where the `new` is written
     * @return array{?ArrayShapeT, array<string, ParamAccessor>}
     */
    private function constructedMembers(Node\Expr\New_ $new, string $fqcn, Scope $scope, array $paramNames): array
    {
        if (! $new->class instanceof Node\Name || $scope->resolveName($new->class) !== $fqcn) {
            return [null, []];
        }

        $args = ConstructorArgs::named($new, $this->constructorParameterNames($fqcn));
        if ($args === []) {
            return [null, []];
        }

        $fields = [];
        $provenance = [];
        foreach ($args as $name => $value) {
            $sensitive = SensitiveConstant::label($value);
            $literal = $sensitive === null ? $this->constLiteralOf($value, $scope) : null;
            if ($literal === null && $sensitive === null) {
                $accessor = AccessorExtractor::fromExpr($value, $paramNames);
                if ($accessor === null && AccessorExtractor::isConditional($value)) {
                    continue;
                }
                if ($accessor !== null) {
                    $provenance[$name] = $accessor;
                }
            }
            $fields[] = new ArrayShapeField($name, $literal ?? new UnknownT($sensitive === null ? 'constructor argument not folded' : 'sensitive constant'));
        }

        return [new ArrayShapeT($fields), $provenance];
    }

    /**
     * In declaration order, for positional binding; empty when the class has no readable constructor.
     *
     * @return list<string>
     */
    private function constructorParameterNames(string $fqcn): array
    {
        if (! $this->reflectionProvider->hasClass($fqcn)) {
            return [];
        }
        $class = $this->reflectionProvider->getClass($fqcn);
        if (! $class->hasConstructor()) {
            return [];
        }

        $names = [];
        foreach ($class->getConstructor()->getVariants()[0]->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
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
     * An argument the call site didn't pass at all is the one case the two payload kinds part company: an
     * object member came from a constructor argument, so an unsupplied one means the member isn't in this
     * response's body ({@see RefinedResponse::withoutMember()}), whereas an array-shape member is PHPStan's
     * own account of the body and only loses its provenance.
     *
     * @param  list<string>  $paramNames  the caller's parameter names
     */
    private function bindPayload(RefinedResponse $child, Callee $callee, Node\Expr $call, Scope $scope, array $paramNames): RefinedResponse
    {
        $objectMembers = $child->payloadMembers !== null;
        if ($child->payloadParamProvenance === [] || (! $objectMembers && ! $child->payload instanceof ArrayShapeT)) {
            return $child;
        }

        // Classify each member's forwarded argument, then let RefinedResponse apply the pure rewrite.
        foreach ($child->payloadParamProvenance as $key => $accessor) {
            $argExpr = $this->argumentFor($callee, $accessor->param, $call);
            if ($argExpr === null) {
                $child = $objectMembers ? $child->withoutMember($key) : $child->bindMember($key, null, null);

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
     * argument is a concrete enum case, via {@see EnumAccessorFolder}. Null when nothing folds — which
     * includes a constant named like a credential ({@see SensitiveConstant}).
     */
    private function foldAccessorArgument(Node\Expr $argExpr, ParamAccessor $accessor, Scope $scope): ?LiteralT
    {
        if ($accessor->kind === AccessorKind::Identity) {
            return SensitiveConstant::label($argExpr) === null ? $this->constLiteralOf($argExpr, $scope) : null;
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
}
