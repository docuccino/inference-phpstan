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
use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Support\SourceOrder;
use Docuccino\Inference\PhpStan\Throwing\ThrowAnalyzer;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use Docuccino\Inference\PhpStan\Trace\ReturnValueFolder;
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
 * The PHPStan/Larastan {@see TypeEngine}: harvests `MethodReturnStatementsNode` for per-return-path
 * types, runs the 3-layer {@see ThrowAnalyzer}, and drives the interprocedural {@see Tracer}. Every
 * method is total — a failure becomes `UnknownT` plus a warning diagnostic, never an exception.
 *
 * @internal
 */
final class PhpStanTypeEngine implements TypeEngine
{
    /**
     * Per-build memo of callable analyses, so one handler body queried by many routes is analysed once.
     * It lives and dies with the engine instance — one container, one build, one memo.
     *
     * @var array<string, ActionAnalysis>
     */
    private array $callableMemo = [];

    /**
     * Built once per engine so its per-callee memo is reused across routes; lazily, so an engine that
     * never harvests a bare-response return never builds it.
     */
    private ?ResponseShapeRefiner $refiner = null;

    /** Reads the `#[ErrorComponent]` an analysed callable declares for the body it answers with. */
    private ?ComponentDeclarations $declarations = null;

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly EngineConfig $config,
        private readonly TypeTranslator $translator,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly ProjectFilter $projectFilter,
        private readonly ClassMetadataFactory $classMetadataFactory,
        private readonly ProjectFilter $refinerFilter,
        private readonly FileWalks $walks,
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
            // Drain so a mid-analysis throw can't leak the refiner's touched files or its truncation
            // count into the next analysis. No-op on the success path, which already drained.
            $this->drainRefinerFiles();
            $this->refiner?->takeTruncations();
        }
    }

    private function doAnalyze(ActionRef $action): ActionAnalysis
    {
        $node = $this->fileAnalyzer->method($action->file, $action->class, $action->method);

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

        $truncation = $this->refinerTruncation($action->symbol());

        return new ActionAnalysis(
            returns: $returns,
            throws: $throws,
            diagnostics: $truncation === null ? [] : [$truncation],
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
            $scope = $this->fileAnalyzer->stableScope($statement->getScope());
            $shape = $this->siteShape($returnNode->expr, $scope);
            $returns[] = new ReturnSite($shape['type'], $location, $shape['component']);
        }

        return $returns;
    }

    /**
     * With {@see ResponseShapeRefiner} recovery for a generic-erased response. A refinement resolving to a
     * `return null`/void arm (framework delegation) yields {@see VoidT}; anything else is verbatim. The
     * component the recovery walked through is carried beside the type rather than inside it: it says
     * which method answered, not what the value is.
     *
     * @return array{type: DType, component: ComponentDeclaration|null}
     */
    private function siteShape(?Node\Expr $expr, Scope $scope): array
    {
        if ($expr === null) {
            return ['type' => new VoidT, 'component' => null];
        }

        $type = $this->translator->translate($scope->getType($expr));
        if (! $type instanceof ClassT || ! ResponseShapeRefiner::isResponseFqcn($type->fqcn)) {
            return ['type' => $type, 'component' => null];
        }

        // Already rich (our extension typed `response()->json()`/`noContent()`) — authoritative, keep it.
        // Only a bare erased response, or a `new JsonResponse(...)`, goes through helper indirection.
        if ($type->typeArgs !== [] && ! $expr instanceof Node\Expr\New_) {
            return ['type' => $type, 'component' => null];
        }

        $refined = $this->refiner()->refine($expr, $scope);
        if ($refined === null) {
            return ['type' => $type, 'component' => null];
        }
        if ($refined->delegates) {
            return ['type' => new VoidT, 'component' => null];
        }

        return [
            'type' => $refined->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE) ?? $type,
            'component' => $refined->component,
        ];
    }

    /**
     * @return list<string>
     */
    private function drainRefinerFiles(): array
    {
        return $this->refiner === null ? [] : $this->refiner->takeFiles();
    }

    /**
     * A response whose shape recovery ran out of descent depth or file budget is documented as its bare
     * declared type — true, but poorer than the code says, so it is reported rather than degrading
     * quietly. Always drained, so a truncation can't be attributed to the next analysis.
     */
    private function refinerTruncation(string $symbol): ?Diagnostic
    {
        $truncations = $this->refiner === null ? 0 : $this->refiner->takeTruncations();
        if ($truncations === 0) {
            return null;
        }

        return new Diagnostic(
            Severity::Info,
            'inference.response-shape-truncated',
            sprintf(
                'Response-shape recovery in %s stopped at its descent bound %d time(s); the response is documented as its declared type. Shorten the helper chain, or state the response explicitly.',
                $symbol,
                $truncations,
            ),
        );
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
            return new ActionAnalysis(
                diagnostics: [new Diagnostic(
                    Severity::Warning,
                    'inference.callable-failed',
                    sprintf('Analysis of %s failed: %s', $callable->symbol(), $e->getMessage()),
                )],
                dependencyFiles: [$callable->file],
            );
        } finally {
            // An analysis must not inherit a failed sibling's dependencies or its truncation count.
            $this->drainRefinerFiles();
            $this->refiner?->takeTruncations();
        }
    }

    private function doAnalyzeCallable(CallableRef $callable): ActionAnalysis
    {
        $method = $callable->method;
        $node = $method === null
            ? ($this->fileAnalyzer->closures($callable->file)[$callable->line] ?? null)
            : $this->fileAnalyzer->method($callable->file, $callable->class, $method);

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
        $truncation = $this->refinerTruncation($callable->symbol());

        // The analysed callable is the outermost hop on every path below it, so its own declaration wins
        // over any it descended through — and it is the only anchor a one-body renderer (an exception's
        // own `render()`) has. The file that method is WRITTEN in joins the deps whether or not it declares
        // anything, for the reason {@see ResponseShapeRefiner::declared()} states: an unoverridden method
        // belongs to the parent and a trait-imported one to the trait, neither of which `$callable->file`
        // names, and the absence of a name there is an answer too.
        $entry = $this->entryDeclaration($callable);
        $entryFile = $this->entryDeclarationFile($callable);

        return new ActionAnalysis(
            returns: $entry === null
                ? $narrowed['returns']
                : array_map(static fn (ReturnSite $site): ReturnSite => $site->withComponent($entry), $narrowed['returns']),
            diagnostics: $truncation === null ? $narrowed['diagnostics'] : [...$narrowed['diagnostics'], $truncation],
            dependencyFiles: [
                $callable->file,
                ...($entryFile === null ? [] : [$entryFile]),
                ...$this->drainRefinerFiles(),
            ],
        );
    }

    /** The `#[ErrorComponent]` the analysed callable itself declares; closures have nowhere to carry one. */
    private function entryDeclaration(CallableRef $callable): ?ComponentDeclaration
    {
        $class = $callable->class;
        $method = $callable->method;

        return $class === null || $method === null
            ? null
            : $this->declarations()->on($class, $method);
    }

    /** The file the analysed callable's method is written in, which is where a name for it can appear. */
    private function entryDeclarationFile(CallableRef $callable): ?string
    {
        $class = $callable->class;
        $method = $callable->method;

        return $class === null || $method === null
            ? null
            : $this->declarations()->fileFor($class, $method);
    }

    /**
     * Harvest a callable's return sites for a narrowing request. Each site pairs a recovered type with the
     * caught-variable class guard that makes it reachable — from PHPStan's per-return narrowing for an
     * `if ($e instanceof X) return …;` chain, or from the arm's own `instanceof` conditions for a
     * `match (true)` renderer (that outer `match` collapses to one return whose scope leaves `$e`
     * un-narrowed, so the arms have to be read off the AST). Source-order first match wins, matching the
     * runtime semantics of both shapes.
     *
     * Two honesty rules: a broad `return null` early-out (`if (! $request->expectsJson()) return null;`)
     * must not shadow a later per-type response arm, so a broad delegation site loses to any
     * response-producing one; and when a broad guard is chosen ahead of a later exact `instanceof` match,
     * or two arms match exactly, an info diagnostic says so rather than passing the shape off as certain.
     *
     * @return array{returns: list<ReturnSite>, diagnostics: list<Diagnostic>}
     */
    private function harvestNarrowed(ReturnStatementsNode $node, CallableRef $callable): array
    {
        $param = $callable->narrowParameter;
        $narrowTo = $callable->narrowType;

        /** @var list<array{pos: int, line: int, type: DType, component: ComponentDeclaration|null, guard: list<list<string>>, delegates: bool}> $sites */
        $sites = [];
        foreach ($node->getReturnStatements() as $statement) {
            $returnNode = $statement->getReturnNode();
            $expr = $returnNode->expr;
            $scope = $this->fileAnalyzer->stableScope($statement->getScope());

            // One site per arm, so per-arm exception mapping composes with refinement.
            if ($param !== null && $expr instanceof Node\Expr\Match_) {
                foreach ($this->matchArmSites($expr, $param, $scope) as $armSite) {
                    $sites[] = $armSite;
                }

                continue;
            }

            $shape = $this->siteShape($expr, $scope);
            $guard = $param === null ? [] : NarrowingGuard::ofType($this->translator->translate($scope->getType(new Variable($param))));
            $sites[] = [
                'pos' => SourceOrder::of($returnNode),
                'line' => $returnNode->getStartLine(),
                'type' => $shape['type'],
                'component' => $shape['component'],
                'guard' => $guard,
                'delegates' => $this->isDelegation($shape['type']),
            ];
        }

        if ($param === null || $narrowTo === null) {
            return [
                'returns' => array_map(
                    fn (array $s): ReturnSite => new ReturnSite($s['type'], new SourceLocation($callable->file, $s['line']), $s['component']),
                    $sites,
                ),
                'diagnostics' => [],
            ];
        }

        // Control-flow order, then every arm the narrowed type satisfies (empty guard = default branch).
        usort($sites, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $satisfiable = array_values(array_filter(
            $sites,
            fn (array $candidate): bool => NarrowingGuard::satisfiedBy($candidate['guard'], $narrowTo),
        ));

        $chosen = $this->chooseNarrowedSite($satisfiable, $narrowTo);

        return [
            'returns' => $chosen === null
                ? []
                : [new ReturnSite($chosen['type'], new SourceLocation($callable->file, $chosen['line']), $chosen['component'])],
            'diagnostics' => $this->narrowingAmbiguity($satisfiable, $chosen, $narrowTo, $param, $callable),
        ];
    }

    /**
     * The first site in source order that either matches the guard exactly or produces a response; falls
     * back to the first satisfiable one for a genuinely all-delegating renderer.
     *
     * @param  list<array{pos: int, line: int, type: DType, component: ComponentDeclaration|null, guard: list<list<string>>, delegates: bool}>  $satisfiable
     * @return array{pos: int, line: int, type: DType, component: ComponentDeclaration|null, guard: list<list<string>>, delegates: bool}|null
     */
    private function chooseNarrowedSite(array $satisfiable, string $narrowTo): ?array
    {
        foreach ($satisfiable as $site) {
            if (NarrowingGuard::namesExactly($site['guard'], $narrowTo) || ! $site['delegates']) {
                return $site;
            }
        }

        return $satisfiable[0] ?? null;
    }

    /**
     * Expand a `match (true)` body into one site per arm: guard = the `instanceof` classes the arm tests
     * `$param` against (a `default` arm, or a non-`instanceof` condition, is broad), type = the refined arm
     * body. Arm order is preserved via source position.
     *
     * @return list<array{pos: int, line: int, type: DType, component: ComponentDeclaration|null, guard: list<list<string>>, delegates: bool}>
     */
    private function matchArmSites(Node\Expr\Match_ $match, string $param, Scope $scope): array
    {
        $sites = [];
        foreach ($match->arms as $arm) {
            $shape = $this->siteShape($arm->body, $scope);
            $sites[] = [
                'pos' => SourceOrder::of($arm->body),
                'line' => $arm->body->getStartLine(),
                'type' => $shape['type'],
                'component' => $shape['component'],
                'guard' => $arm->conds === null ? [] : $this->armInstanceofGuards($arm->conds, $param, $scope),
                'delegates' => $this->isDelegation($shape['type']),
            ];
        }

        return $sites;
    }

    /**
     * The arm's guard in the shape {@see NarrowingGuard} reads: `&&` requires both, `||` alternates, and
     * an arm's several conditions alternate too — `match (true) { $e instanceof A, $e instanceof B => … }`
     * fires for either, so folding them as requirements would leave both types answered by a later arm.
     * Anything that isn't an `instanceof` on `$param` says nothing about it, which makes the alternative
     * it sits in reachable by anything.
     *
     * @param  array<Node\Expr>  $conds
     * @return list<list<string>>
     */
    private function armInstanceofGuards(array $conds, string $param, Scope $scope): array
    {
        $guard = null;
        foreach ($conds as $cond) {
            $condGuard = $this->condGuard($cond, $param, $scope);
            $guard = $guard === null ? $condGuard : NarrowingGuard::anyOf($guard, $condGuard);
        }

        return $guard ?? [];
    }

    /**
     * @return list<list<string>>
     */
    private function condGuard(Node\Expr $node, string $param, Scope $scope): array
    {
        if ($node instanceof Node\Expr\Instanceof_
            && $node->expr instanceof Variable
            && $node->expr->name === $param
            && $node->class instanceof Node\Name
        ) {
            return [[$scope->resolveName($node->class)]];
        }

        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return NarrowingGuard::allOf(
                $this->condGuard($node->left, $param, $scope),
                $this->condGuard($node->right, $param, $scope),
            );
        }

        if ($node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            return NarrowingGuard::anyOf(
                $this->condGuard($node->left, $param, $scope),
                $this->condGuard($node->right, $param, $scope),
            );
        }

        // Every other expression — a comparison, a call, a negation — says nothing about `$param`.
        return [];
    }

    private function isDelegation(DType $type): bool
    {
        return $type instanceof VoidT || $type instanceof NullT;
    }

    /**
     * Raised when the chosen site is a broad guard shadowing a later exact `instanceof` match, or two arms
     * claim the type exactly. An exact site with no rival is unambiguous, as is the ordinary
     * sequential-`instanceof`-plus-default shape.
     *
     * @param  list<array{pos: int, line: int, type: DType, component: ComponentDeclaration|null, guard: list<list<string>>, delegates: bool}>  $satisfiable
     * @param  array{pos: int, line: int, type: DType, component: ComponentDeclaration|null, guard: list<list<string>>, delegates: bool}|null  $chosen
     * @return list<Diagnostic>
     */
    private function narrowingAmbiguity(array $satisfiable, ?array $chosen, string $narrowTo, string $param, CallableRef $callable): array
    {
        if ($chosen === null) {
            return [];
        }

        $exactMatches = array_filter($satisfiable, static fn (array $s): bool => NarrowingGuard::namesExactly($s['guard'], $narrowTo));
        $chosenIsExact = NarrowingGuard::namesExactly($chosen['guard'], $narrowTo);

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

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->classMetadataFactory->forClass($class);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        if ($action->class === null) {
            // A closure located by line: its returns are the harvest (a `RateLimiter::for` closure folded
            // to a concrete limit). Walked in place, never interprocedurally — a limiter that delegates
            // its limit to a helper doesn't fold.
            if ($action->method === '{closure}') {
                $this->traceClosure($action, $visitor);
            }

            return new TraceReport([$action->file]);
        }

        $tracer = new Tracer(
            $this->adapter,
            $this->walks,
            $this->translator,
            $this->projectFilter,
            new CalleeResolver($this->adapter->reflectionProvider()),
            // Stateless; the expensive half it reads is the per-file analysis, which IS shared.
            new ReturnValueFolder($this->fileAnalyzer, $this->adapter->reflectionProvider()),
            $visitor,
            $this->config->traceDepth,
            $this->config->fileBudget,
            $this->config->vendorPath,
        );

        try {
            $tracer->run($action->class, $action->method, $action->file);
        } catch (Throwable) {
            // Best-effort: the visitor keeps what it harvested, the report keeps every file reached.
        }

        return new TraceReport($tracer->visitedFiles());
    }

    /**
     * Hand a closure's return expressions to the visitor with the flow-refined scope at each return, so it
     * folds them as it would inside a method walk. The closure is located by start line, and both shapes
     * are handled: a full closure (`ClosureReturnStatementsNode`, where `isAlwaysTerminating()` tells a
     * fall-through body apart so a limiter that doesn't always return stays unrecovered) and an arrow
     * function (`InArrowFunctionNode`, one implicit return).
     *
     * The visitor runs inside the pass, on the RAW live scope — `$statement->getScope()` for a full closure,
     * the callback scope itself for an arrow function — because a return's flow-refined scope is what folds
     * its expression, and nothing may be deferred: a raw scope is a lazy fiber scope that cannot type
     * expressions once its pass has ended.
     *
     * That raw scope is also why this is the one walk that goes straight to the adapter rather than through
     * {@see FileWalks}, and the reason is worth stating exactly, because the obvious one is wrong: a
     * STABILISED arrow-function scope answers after the pass perfectly well, so it is not that closures
     * cannot be replayed. It is that a recording holds only stabilised scopes, so it has nothing to hand a
     * visitor that must see the raw one.
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
            // Best-effort; the visitor keeps whatever it harvested.
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

    private function declarations(): ComponentDeclarations
    {
        return $this->declarations ??= new ComponentDeclarations($this->adapter->reflectionProvider());
    }

    private function refiner(): ResponseShapeRefiner
    {
        return $this->refiner ??= new ResponseShapeRefiner(
            $this->adapter,
            $this->translator,
            $this->fileAnalyzer,
            new CalleeResolver($this->adapter->reflectionProvider()),
            // Prime-scoped filter, not $this->projectFilter: render helpers can live in any primed app
            // root (`Modules\…`), outside the descend scope throws/QB-trace use. Vendor still never folds.
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
