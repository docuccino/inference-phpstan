<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Throwing\ThrowAnalyzer;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use Docuccino\Inference\PhpStan\Trace\Tracer;
use Docuccino\Inference\PhpStan\Trace\TypeScopeImpl;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\InArrowFunctionNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Node\ReturnStatementsNode;
use Throwable;

/**
 * The single-process PHPStan/Larastan {@see TypeEngine} (Phase 2a). It harvests
 * `MethodReturnStatementsNode` for per-return-path types, runs the 3-layer
 * {@see ThrowAnalyzer}, and drives the interprocedural {@see Tracer}. Every
 * method is total: a per-action try/catch turns any failure into `UnknownT` + a
 * warning diagnostic rather than throwing (design §3).
 *
 * Not built here (Phase 2b): worker orchestration, recycling/bisection, the
 * engine result cache. The seams are present — `dependencyFiles` feeds the
 * cache key; the adapter is swappable per PHPStan minor — but no stubs.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class PhpStanTypeEngine implements TypeEngine
{
    /**
     * Per-build memo of callable analyses, keyed by {@see CallableRef::symbol()} (arch I1). A render
     * callback (or any callable) analysed once is reused across every route that reaches it — the
     * inferred-handler tier queries the same handler bodies for many routes.
     *
     * NOTE (deliberate deferral): under the worker orchestrator each worker holds its OWN memo, so a
     * callable analysed on two workers is analysed twice. A shared cross-worker callable cache is
     * deferred — it needs the same serialize/transport plumbing as the
     * engine result cache and is not built here.
     *
     * @var array<string, ActionAnalysis>
     */
    private array $callableMemo = [];

    /**
     * The response-shape refiner (helper-indirection recovery), built once per engine so its per-callee
     * memo is reused across every route reaching a shared helper. Lazy: an engine that never harvests a
     * bare-response return never builds it.
     */
    private ?ResponseShapeRefiner $refiner = null;

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly EngineConfig $config,
        private readonly TypeTranslator $translator,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly ProjectFilter $projectFilter,
        private readonly ClassMetadataFactory $classMetadataFactory,
        private readonly ProjectFilter $refinerFilter,
    ) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        try {
            return $this->doAnalyze($action);
        } catch (Throwable $e) {
            return new ActionAnalysis(
                returns: [new ReturnSite(
                    new UnknownT('analysis failed: '.$e->getMessage()),
                    new SourceLocation($action->file, $action->line),
                )],
                throws: [],
                diagnostics: [new Diagnostic(
                    Severity::Warning,
                    'inference.action-failed',
                    sprintf('Type analysis of %s failed: %s', $action->symbol(), $e->getMessage()),
                )],
                dependencyFiles: [$action->file],
            );
        } finally {
            // See analyzeCallableUncached: drain so a mid-analysis throw cannot leak the refiner's
            // touched files into the next analysis's dependency set (a no-op on the success path,
            // which already drained while building its result).
            $this->drainRefinerFiles();
        }
    }

    private function doAnalyze(ActionRef $action): ActionAnalysis
    {
        $methods = $this->fileAnalyzer->analyze($action->file);
        $node = $methods[$action->method] ?? null;

        if (! $node instanceof MethodReturnStatementsNode) {
            return new ActionAnalysis(
                returns: [],
                throws: [],
                diagnostics: [new Diagnostic(
                    Severity::Warning,
                    'inference.method-not-found',
                    sprintf('No analysable method body for %s.', $action->symbol()),
                )],
                dependencyFiles: [$action->file],
            );
        }

        $returns = $this->harvestReturns($node, $action->file);

        $throwAnalyzer = $this->makeThrowAnalyzer();
        $throws = $throwAnalyzer->analyze($node, $this->selfLabel($action));

        $diagnostics = [];
        $dropped = $throwAnalyzer->droppedCount();
        if ($dropped > 0) {
            $diagnostics[] = new Diagnostic(
                Severity::Hint,
                'inference.throw-noise-dropped',
                sprintf('Dropped %d implicit "any-throwable" point(s) in %s.', $dropped, $action->symbol()),
            );
        }

        return new ActionAnalysis(
            returns: $returns,
            throws: $throws,
            diagnostics: $diagnostics,
            dependencyFiles: [$action->file, ...$throwAnalyzer->visitedFiles(), ...$this->drainRefinerFiles()],
        );
    }

    /**
     * @return list<ReturnSite>
     */
    private function harvestReturns(MethodReturnStatementsNode $node, string $file): array
    {
        $returns = [];
        foreach ($node->getReturnStatements() as $statement) {
            $returnNode = $statement->getReturnNode();
            $location = new SourceLocation($file, $returnNode->getStartLine());
            $returns[] = new ReturnSite($this->siteType($returnNode->expr, $statement->getScope()), $location);
        }

        return $returns;
    }

    /**
     * The type of one return expression, with response-shape refinement (design §4 helper indirection):
     * a bare `JsonResponse`/`Response` built through a project-code helper is followed to the helper's
     * own return sites and substituted with the richer `JsonResponse<payload, status, contentType>`. A
     * refinement that resolves to a `return null` / void arm (the "delegate to the framework" path)
     * yields {@see VoidT}. Anything not a bare response is translated verbatim.
     */
    private function siteType(?Node\Expr $expr, Scope $scope): DType
    {
        if ($expr === null) {
            return new VoidT;
        }

        $type = $this->translator->translate($scope->getType($expr));
        if (! $type instanceof ClassT || ! ResponseShapeRefiner::isResponseFqcn($type->fqcn)) {
            return $type;
        }

        // Already rich (`response()->json()`/`noContent()` typed by the bundled extension): keep it
        // verbatim — its shape (incl. a void `noContent` payload) is authoritative, nothing to refine.
        // Only a bare erased response, or a `new JsonResponse(...)` the extension does not cover, is
        // followed through helper indirection.
        if ($type->typeArgs !== [] && ! $expr instanceof Node\Expr\New_) {
            return $type;
        }

        $refined = $this->refiner()->refine($expr, $scope);
        if ($refined === null) {
            return $type;
        }
        if ($refined->delegates) {
            return new VoidT;
        }

        return $refined->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE) ?? $type;
    }

    /**
     * Drain (and reset) the refiner's touched-file set for the current analysis, or `[]` if it never ran.
     *
     * @return list<string>
     */
    private function drainRefinerFiles(): array
    {
        return $this->refiner === null ? [] : $this->refiner->takeFiles();
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        return $this->callableMemo[$callable->symbol()] ??= $this->analyzeCallableUncached($callable);
    }

    private function analyzeCallableUncached(CallableRef $callable): ActionAnalysis
    {
        try {
            return $this->doAnalyzeCallable($callable);
        } catch (Throwable $e) {
            // Drain in a finally: files the refiner touched before a mid-analysis throw would otherwise
            // leak into the NEXT analysis's dependencyFiles (over-invalidation only, never under — but
            // an analysis must not inherit a failed sibling's dependencies).
            return new ActionAnalysis(
                diagnostics: [new Diagnostic(
                    Severity::Warning,
                    'inference.callable-failed',
                    sprintf('Analysis of %s failed: %s', $callable->symbol(), $e->getMessage()),
                )],
                dependencyFiles: [$callable->file],
            );
        } finally {
            $this->drainRefinerFiles();
        }
    }

    private function doAnalyzeCallable(CallableRef $callable): ActionAnalysis
    {
        $method = $callable->method;
        $node = $method === null
            ? ($this->fileAnalyzer->closures($callable->file)[$callable->line] ?? null)
            : ($this->fileAnalyzer->analyze($callable->file)[$method] ?? null);

        if (! $node instanceof ReturnStatementsNode) {
            return new ActionAnalysis(
                diagnostics: [new Diagnostic(
                    Severity::Info,
                    'inference.callable-not-found',
                    sprintf('No analysable body for %s.', $callable->symbol()),
                )],
                dependencyFiles: [$callable->file],
            );
        }

        $narrowed = $this->harvestNarrowed($node, $callable);

        return new ActionAnalysis(
            returns: $narrowed['returns'],
            diagnostics: $narrowed['diagnostics'],
            dependencyFiles: [$callable->file, ...$this->drainRefinerFiles()],
        );
    }

    /**
     * Harvest a callable's return sites for a narrowing request. Each site pairs a recovered type (with
     * response-shape refinement — {@see siteType()}) with the caught-variable class GUARD that makes it
     * reachable: for an `if ($e instanceof X) return …;` chain, PHPStan's per-return narrowing; for a
     * `return match (true) { $e instanceof X => …, default => … }` renderer (a common real-world shape), the arm's
     * own `instanceof` conditions (decomposed here — the outer `match` collapses to one return whose
     * scope leaves `$e` un-narrowed, so the arms must be read from the AST). The reachable site for the
     * narrowed type is chosen by SOURCE-ORDER-FIRST-MATCH over the arms — the runtime semantics of both
     * an `if`-chain and `match (true)`.
     *
     * Delegation honesty (design §6): a broad `return null` / void arm that does NOT branch on `$e`
     * (the `if (! $request->expectsJson()) return null;` early-out) must not shadow a later per-type
     * response arm — the documented API path is the response, not the framework fall-through. So a
     * broad DELEGATION site is skipped in favour of any response-producing site; only a genuine
     * per-type null arm (`$e instanceof X => null`, an exact guard) or an all-delegating renderer
     * resolves to delegation.
     *
     * Narrowing honesty (B2): when a broad guard is chosen ahead of a later EXACT `instanceof` match,
     * or two arms match the type exactly, an info diagnostic is raised so the shape is not passed off
     * as unambiguous.
     *
     * @return array{returns: list<ReturnSite>, diagnostics: list<Diagnostic>}
     */
    private function harvestNarrowed(ReturnStatementsNode $node, CallableRef $callable): array
    {
        $param = $callable->narrowParameter;
        $narrowTo = $callable->narrowType;

        /** @var list<array{pos: int, line: int, type: DType, guard: list<string>, delegates: bool}> $sites */
        $sites = [];
        foreach ($node->getReturnStatements() as $statement) {
            $returnNode = $statement->getReturnNode();
            $expr = $returnNode->expr;
            $scope = $statement->getScope();

            // A `match (true)` renderer body decomposes into one site per arm (guard = the arm's
            // `instanceof` conditions), so per-arm exception mapping composes with refinement.
            if ($param !== null && $expr instanceof Node\Expr\Match_) {
                foreach ($this->matchArmSites($expr, $param, $scope) as $armSite) {
                    $sites[] = $armSite;
                }

                continue;
            }

            $type = $this->siteType($expr, $scope);
            $guard = $param === null ? [] : $this->classFqcns($this->translator->translate($scope->getType(new Variable($param))));
            $sites[] = [
                'pos' => $this->sourcePos($returnNode),
                'line' => $returnNode->getStartLine(),
                'type' => $type,
                'guard' => $guard,
                'delegates' => $this->isDelegation($type),
            ];
        }

        if ($param === null || $narrowTo === null) {
            return [
                'returns' => array_map(
                    fn (array $s): ReturnSite => new ReturnSite($s['type'], new SourceLocation($callable->file, $s['line'])),
                    $sites,
                ),
                'diagnostics' => [],
            ];
        }

        // Deterministic control-flow order, then every arm whose caught-variable guard the narrowed
        // type satisfies (an empty/unclassed guard is the unconditional default branch).
        usort($sites, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $satisfiable = array_values(array_filter(
            $sites,
            fn (array $candidate): bool => $this->guardSatisfies($candidate['guard'], $narrowTo),
        ));

        $chosen = $this->chooseNarrowedSite($satisfiable, $narrowTo);

        return [
            'returns' => $chosen === null
                ? []
                : [new ReturnSite($chosen['type'], new SourceLocation($callable->file, $chosen['line']))],
            'diagnostics' => $this->narrowingAmbiguity($satisfiable, $chosen, $narrowTo, $param, $callable),
        ];
    }

    /**
     * Choose the reachable site for the narrowed type: the first (in source order) that is an EXACT
     * guard match or produces a response — so a broad delegation early-out is skipped in favour of the
     * per-type response arm. Falls back to the first satisfiable site (a genuinely all-delegating
     * renderer) when nothing else qualifies.
     *
     * @param  list<array{pos: int, line: int, type: DType, guard: list<string>, delegates: bool}>  $satisfiable
     * @return array{pos: int, line: int, type: DType, guard: list<string>, delegates: bool}|null
     */
    private function chooseNarrowedSite(array $satisfiable, string $narrowTo): ?array
    {
        foreach ($satisfiable as $site) {
            if (in_array($narrowTo, $site['guard'], true) || ! $site['delegates']) {
                return $site;
            }
        }

        return $satisfiable[0] ?? null;
    }

    /**
     * Expand a `match (true)` body into one site per arm: guard = the `instanceof` classes the arm
     * conditions test `$param` against (a `default` arm, or a non-`instanceof` condition, is broad),
     * type = the refined arm-body response. Arm order is preserved via source position.
     *
     * @return list<array{pos: int, line: int, type: DType, guard: list<string>, delegates: bool}>
     */
    private function matchArmSites(Node\Expr\Match_ $match, string $param, Scope $scope): array
    {
        $sites = [];
        foreach ($match->arms as $arm) {
            $type = $this->siteType($arm->body, $scope);
            $sites[] = [
                'pos' => $this->sourcePos($arm->body),
                'line' => $arm->body->getStartLine(),
                'type' => $type,
                'guard' => $arm->conds === null ? [] : $this->armInstanceofGuards($arm->conds, $param, $scope),
                'delegates' => $this->isDelegation($type),
            ];
        }

        return $sites;
    }

    /**
     * The class FQCNs a match arm's conditions test `$param` against — walking `&&`/`||` so a compound
     * `$e instanceof A && $e instanceof B` contributes both. A condition that is not an `instanceof` on
     * `$param` contributes nothing (the arm stays broad).
     *
     * @param  array<Node\Expr>  $conds
     * @return list<string>
     */
    private function armInstanceofGuards(array $conds, string $param, Scope $scope): array
    {
        $fqcns = [];
        foreach ($conds as $cond) {
            $this->collectInstanceof($cond, $param, $scope, $fqcns);
        }

        return array_values(array_unique($fqcns));
    }

    /**
     * @param  list<string>  $out
     */
    private function collectInstanceof(Node\Expr $node, string $param, Scope $scope, array &$out): void
    {
        if ($node instanceof Node\Expr\Instanceof_
            && $node->expr instanceof Variable
            && $node->expr->name === $param
            && $node->class instanceof Node\Name
        ) {
            $out[] = $scope->resolveName($node->class);

            return;
        }

        if ($node instanceof Node\Expr\BinaryOp) {
            $this->collectInstanceof($node->left, $param, $scope, $out);
            $this->collectInstanceof($node->right, $param, $scope, $out);
        }
    }

    private function isDelegation(DType $type): bool
    {
        return $type instanceof VoidT || $type instanceof NullT;
    }

    private function sourcePos(Node $node): int
    {
        $pos = $node->getStartFilePos();

        return $pos >= 0 ? $pos : $node->getStartLine();
    }

    /**
     * The narrowing-honesty diagnostic (B2): raised when the CHOSEN site is a broad guard shadowing a
     * later exact `instanceof` match, or two arms claim the type exactly — the shape is then not
     * unambiguous. An exact chosen site with no rival exact match, or the ordinary
     * sequential-`instanceof`-plus-default shape, is unambiguous.
     *
     * @param  list<array{pos: int, line: int, type: DType, guard: list<string>, delegates: bool}>  $satisfiable
     * @param  array{pos: int, line: int, type: DType, guard: list<string>, delegates: bool}|null  $chosen
     * @return list<Diagnostic>
     */
    private function narrowingAmbiguity(array $satisfiable, ?array $chosen, string $narrowTo, string $param, CallableRef $callable): array
    {
        if ($chosen === null) {
            return [];
        }

        $exactMatches = array_filter($satisfiable, static fn (array $s): bool => in_array($narrowTo, $s['guard'], true));
        $chosenIsExact = in_array($narrowTo, $chosen['guard'], true);

        // A broad guard shadowed a later exact match, or two branches claim the type exactly.
        $ambiguous = $chosenIsExact ? count($exactMatches) > 1 : $exactMatches !== [];
        if (! $ambiguous) {
            return [];
        }

        return [new Diagnostic(
            Severity::Info,
            'inference.ambiguous-narrowing',
            sprintf(
                'More than one return site is reachable when %s narrows to %s in %s; the first in source order was chosen and the recovered shape may be ambiguous.',
                '$'.$param,
                $narrowTo,
                $callable->symbol(),
            ),
        )];
    }

    /**
     * Whether a return guarded by `$guard` (the caught variable's narrowed class types) is reachable
     * when the caught variable is `$narrowTo`. Empty guard = the default branch (reachable for any).
     *
     * @param  list<string>  $guard
     */
    private function guardSatisfies(array $guard, string $narrowTo): bool
    {
        if ($guard === []) {
            return true;
        }

        foreach ($guard as $guardFqcn) {
            if ($narrowTo === $guardFqcn || is_a($narrowTo, $guardFqcn, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The concrete class FQCNs a DType carries (a single class, or the members of a union /
     * intersection) — the caught variable's narrowed type at a return site.
     *
     * @return list<string>
     */
    private function classFqcns(DType $type): array
    {
        if ($type instanceof ClassT) {
            return [$type->fqcn];
        }

        if ($type instanceof UnionT || $type instanceof IntersectionT) {
            $out = [];
            foreach ($type->members as $member) {
                $out = [...$out, ...$this->classFqcns($member)];
            }

            return $out;
        }

        return [];
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->classMetadataFactory->forClass($class);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        if ($action->class === null) {
            // A closure located by line (not a class method): its returns ARE the harvest — a named
            // rate limiter's `RateLimiter::for` closure folded to a concrete limit. Walked in place,
            // never interprocedurally: a limiter that delegates its limit to a helper does not fold.
            if ($action->method === '{closure}') {
                $this->traceClosure($action, $visitor);
            }

            return new TraceReport([$action->file]);
        }

        $tracer = new Tracer(
            $this->adapter,
            $this->translator,
            $this->projectFilter,
            new CalleeResolver($this->adapter->reflectionProvider()),
            $visitor,
            $this->config->traceDepth,
            $this->config->fileBudget,
            $this->config->vendorPath,
        );

        try {
            $tracer->run($action->class, $action->method, $action->file);
        } catch (Throwable) {
            // Trace is best-effort; the visitor keeps whatever it harvested and
            // the report still carries every file the walk reached before failing.
        }

        return new TraceReport($tracer->visitedFiles());
    }

    /**
     * Hand a closure's return expressions to the visitor, each with the flow-refined scope in effect
     * at that return — so it constant-folds them exactly as it would inside a method walk. The
     * closure is located by start line (from `ReflectionFunction`) and both shapes are reached, so an
     * idiomatic `fn ($r) => Limit::…` arrow limiter folds, not only a `function () { return …; }` one:
     *
     *   - a full closure (`ClosureReturnStatementsNode`) — every explicit return with its scope, and
     *     `isAlwaysTerminating()` telling a conditional (fall-through) body apart from an unconditional
     *     one so a limiter that does not always return is left unrecovered;
     *   - an arrow function (`InArrowFunctionNode`) — its single implicit return of the body.
     *
     * The visitor is driven INSIDE the pass, on the live scope: an arrow function's scope is a lazy
     * fiber scope that cannot type expressions once the pass has ended, so nothing may be deferred.
     * Best-effort — any failure leaves the visitor with whatever it already harvested.
     */
    private function traceClosure(ActionRef $action, TraceVisitor $visitor): void
    {
        try {
            $this->adapter->processFile($action->file, function (Node $node, Scope $scope) use ($action, $visitor): void {
                // @phpstan-ignore phpstanApi.instanceofAssumption
                if ($node instanceof ClosureReturnStatementsNode
                    && $node->getClosureExpr()->getStartLine() === $action->line
                ) {
                    if (! $node->getStatementResult()->isAlwaysTerminating()) {
                        return; // can fall through ⇒ conditional; nothing safe to fold
                    }
                    foreach ($node->getReturnStatements() as $statement) {
                        $expr = $statement->getReturnNode()->expr;
                        if ($expr !== null) {
                            $visitor->enterNode($expr, new TypeScopeImpl($statement->getScope(), $this->translator));
                        }
                    }

                    return;
                }

                // @phpstan-ignore phpstanApi.instanceofAssumption
                if ($node instanceof InArrowFunctionNode
                    && $node->getOriginalNode()->getStartLine() === $action->line
                ) {
                    $visitor->enterNode($node->getOriginalNode()->expr, new TypeScopeImpl($scope, $this->translator));
                }
            });
        } catch (Throwable) {
            // Trace is best-effort; the visitor keeps whatever it harvested.
        }
    }

    private function makeThrowAnalyzer(): ThrowAnalyzer
    {
        return new ThrowAnalyzer(
            $this->adapter->reflectionProvider(),
            $this->projectFilter,
            $this->fileAnalyzer,
            $this->config->knownThrowers,
            new CalleeResolver($this->adapter->reflectionProvider()),
            $this->config->throwDepth,
        );
    }

    private function refiner(): ResponseShapeRefiner
    {
        return $this->refiner ??= new ResponseShapeRefiner(
            $this->adapter,
            $this->translator,
            $this->fileAnalyzer,
            new CalleeResolver($this->adapter->reflectionProvider()),
            // The refiner (and its enum folder) follow error-render helpers across any PRIMED app
            // source root — a modular monorepo keeps them in `Modules\…`, outside the descend scope
            // throws/QB-trace use — so it takes the prime-scoped filter, not $this->projectFilter.
            // Vendor is still never followed (it is not a primed root).
            $this->refinerFilter,
            $this->adapter->reflectionProvider(),
            $this->config->traceDepth,
            $this->config->fileBudget,
        );
    }

    private function selfLabel(ActionRef $action): string
    {
        $class = $action->class !== null
            ? Fqcn::short($action->class)
            : basename($action->file, '.php');

        return $class.'::'.$action->method;
    }
}
