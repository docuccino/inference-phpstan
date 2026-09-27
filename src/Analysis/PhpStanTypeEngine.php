<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\CallCondition;
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
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Support\SourceOrder;
use Docuccino\Inference\PhpStan\Throwing\AnalyzedBodies;
use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use Docuccino\Inference\PhpStan\Throwing\FactoryStatus;
use Docuccino\Inference\PhpStan\Throwing\HttpExceptionStatus;
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
use PHPStan\Type\ObjectType;
use Throwable;

/**
 * The PHPStan/Larastan {@see TypeEngine}: harvests `MethodReturnStatementsNode` for per-return-path
 * types, runs the 3-layer {@see ThrowAnalyzer}, and drives the interprocedural {@see Tracer}. Every
 * method is total — a failure becomes `UnknownT` plus a warning diagnostic, never an exception.
 *
 * @phpstan-type NarrowedSite array{pos: int, line: int, type: DType, component: ComponentDeclaration|null, guard: list<list<string>>, delegates: bool, echoes: string|null, conditions: list<CallCondition>, scope: Scope|null}
 *
 * @internal
 */
final class PhpStanTypeEngine implements TypeEngine
{
    /**
     * Per-build memo of action analyses, so one controller method is analysed once however many routes
     * reach it — and once for a whole export run rather than once per version document, since every
     * document asks the identical {@see ActionRef} sequence and the answer is a function of the ref alone.
     * Keyed on the ref's whole tuple and not on {@see ActionRef::symbol()}: see {@see actionKey()}.
     *
     * @var array<string, ActionAnalysis>
     */
    private array $actionMemo = [];

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

    /**
     * Built once per engine, so an exception class thrown by forty routes is read once. Each answer is a
     * function of the class — or of the class and one factory name — alone, which is what makes the memo
     * sound across routes.
     */
    private ?HttpExceptionStatus $httpExceptionStatus = null;

    private ?FactoryStatus $factoryStatus = null;

    private ?ClassBodies $classBodies = null;

    /**
     * The publishable form of a ref's label. Both {@see ActionRef::symbol()} and
     * {@see CallableRef::target()} fall back to the FILE where there is no class, and both say in so many
     * words that this makes them identity keys rather than something a diagnostic may print — so a
     * closure route named one absolutely in every message below until they came through here. No base
     * path has to be threaded in: the resolver's ladder finds the checkout from the file itself.
     *
     * The adapter relativises every action-analysis message again on the way into the document, which
     * is why nothing published ever carried the path — but the callable-side diagnostics cross no such
     * seam, and an engine is a contract another host can call, so the label leaves here publishable.
     * {@see ThrowAnalyzer} is handed the same relativiser for the same reason: the site an unread-status
     * notice names comes straight off the analyser, so it crosses here rather than incidentally later.
     */
    private readonly MessagePaths $labels;

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly EngineConfig $config,
        private readonly TypeTranslator $translator,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly ProjectFilter $projectFilter,
        private readonly ClassMetadataFactory $classMetadataFactory,
        private readonly ProjectFilter $appFilter,
        // The descend scope the host would have used with nothing configured: not a scope anything
        // walks by, only the one that says which declined hops are the host's narrowing to undo.
        private readonly ProjectFilter $declaredFilter,
        private readonly FileWalks $walks,
    ) {
        $this->labels = new MessagePaths(new RootRelativeSourcePathResolver(''));
    }

    /** A ref's label, relativised — {@see $labels}. Never the memo key, which stays the raw identity. */
    private function label(ActionRef|CallableRef $ref): string
    {
        return $this->labels->relative($ref->symbol());
    }

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        return $this->actionMemo[self::actionKey($action)] ??= $this->analyzeActionUncached($action);
    }

    /**
     * The whole ref, and not {@see ActionRef::symbol()}, because symbol() is a LABEL and not an identity:
     * a closure route carries no class, so every closure in one routes file collapses onto
     * `routes/api.php::{closure}` there. What the answer can depend on is the file, the declaring class and
     * the method — {@see FileAnalyzer::method()} reads exactly those — plus the line, which is not there
     * merely to be reported: {@see traceClosure()} SELECTS which closure it walks by
     * `getStartLine() === $action->line`, so two refs differing only in line name two different bodies
     * and get two different answers. Two refs equal on all four are indistinguishable to this engine,
     * which is what lets one answer serve both.
     */
    private static function actionKey(ActionRef $action): string
    {
        return $action->file."\0".($action->class ?? '')."\0".$action->method."\0".$action->line;
    }

    private function analyzeActionUncached(ActionRef $action): ActionAnalysis
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
                    sprintf('Type analysis of %s failed: %s', $this->label($action), $e->getMessage()),
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
                    sprintf('No analysable method body for %s.', $this->label($action)),
                )],
                dependencyFiles: [$action->file],
            );
        }

        $returns = $this->harvestReturns($node, $action->file);

        $throwAnalyzer = $this->makeThrowAnalyzer();
        $throws = $throwAnalyzer->analyze($node, $this->selfLabel($action));

        $truncation = $this->refinerTruncation($this->label($action));
        $diagnostics = $throwAnalyzer->diagnostics();

        return new ActionAnalysis(
            returns: $returns,
            throws: $throws,
            diagnostics: $truncation === null ? $diagnostics : [...$diagnostics, $truncation],
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

        // Already rich (our extension typed `response()->json()`/`noContent()`) — authoritative, keep it,
        // unless the refiner reads the expression better than its resolved type does
        // ({@see ResponseShapeRefiner::outranksResolvedType()}): a `new JsonResponse(...)`, or a fluent
        // chain whose `->setStatusCode()` the erased generic carried straight past.
        if ($type->typeArgs !== [] && ! $this->refiner()->outranksResolvedType($expr, $scope)) {
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
     * A response whose shape recovery ran out of descent depth or file budget is recovered as its bare
     * declared type — true, but poorer than the code says, so it is reported rather than degrading
     * quietly. Always drained, so a truncation can't be attributed to the next analysis.
     *
     * The sentence speaks of the RECOVERY and not of the finished response, because the engine cannot see
     * the document and `#[Response(type: …)]` answers the same node
     * (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it never reads").
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
                'Response-shape recovery in %s stopped at its descent bound %d time(s); the shape recovered for the response is its bare declared type.',
                $symbol,
                $truncations,
            ),
            help: 'Flatten the chain between the `return` and the value it builds — every project-code call on the way is one hop, and the bound is not something config can raise. That is the only thing that clears this: stating the shape at a later layer corrects the document and leaves this notice naming the callable.',
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
                    sprintf('Analysis of %s failed: %s', $this->label($callable), $e->getMessage()),
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
        if ($method === null) {
            $body = $this->fileAnalyzer->callableAtLine($callable->file, $callable->line);
        } else {
            $node = $this->fileAnalyzer->method($callable->file, $callable->class, $method);
            $body = $node === null ? null : CallableBody::ofMethod($node);
        }

        if ($body === null) {
            return new ActionAnalysis(
                diagnostics: [new Diagnostic(
                    Severity::Info,
                    'inference.callable-not-found',
                    sprintf('No analysable body for %s.', $this->label($callable)),
                )],
                dependencyFiles: [$callable->file],
            );
        }

        $narrowed = $this->harvestNarrowed($body, $callable);
        $truncation = $this->refinerTruncation($this->label($callable));

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
     * A ternary is the same conditional spelled inline, so it expands the way a `match` does — one site
     * per branch, each typed in the scope its condition leaves — rather than collapsing to the one type
     * both branches share, which for two responses is the supertype that says neither.
     *
     * With {@see CallableRef::$narrowToEvery} nothing is chosen: every site the narrowed type can reach
     * comes back, in source order, each carrying the parameter it returns unchanged and the literal
     * parameter calls its scope proves ({@see ParameterUse}).
     *
     * @return array{returns: list<ReturnSite>, diagnostics: list<Diagnostic>}
     */
    private function harvestNarrowed(CallableBody $body, CallableRef $callable): array
    {
        $param = $callable->narrowParameter;
        $narrowTo = $callable->narrowType;
        $every = $callable->narrowToEvery;
        $probes = $every ? ParameterUse::literalCalls($body->parameters, $body->nodes) : [];

        /** @var list<NarrowedSite> $sites */
        $sites = [];
        foreach ($body->returns as $return) {
            $expr = $return['expr'];
            $returnNode = $return['at'];
            $scope = $this->fileAnalyzer->stableScope($return['scope']);

            // One site per arm, so per-arm exception mapping composes with refinement — and, read for every
            // return, so each arm's answer is one of them even where no parameter is narrowed.
            $expands = $param !== null || $every;
            if ($expands && $expr instanceof Node\Expr\Match_) {
                foreach ($this->matchArmSites($expr, $param, $scope, $body, $probes, $every) as $armSite) {
                    $sites[] = $armSite;
                }

                continue;
            }

            if ($expands && $expr instanceof Node\Expr\Ternary && $expr->if !== null) {
                foreach ($this->branches($expr, $scope) as [$branch, $branchScope]) {
                    $sites[] = $this->site($branch, $branch, $branchScope, $this->paramGuard($param, $branchScope), $body, $probes, $every);
                }

                continue;
            }

            $sites[] = $this->site($expr, $returnNode, $scope, $this->paramGuard($param, $scope), $body, $probes, $every);
        }

        if ($param === null || $narrowTo === null) {
            return ['returns' => $this->returnSites($sites, $callable), 'diagnostics' => []];
        }

        // Control-flow order, then every arm the narrowed type satisfies (empty guard = default branch).
        usort($sites, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $satisfiable = array_values(array_filter(
            $sites,
            fn (array $candidate): bool => NarrowingGuard::satisfiedBy($candidate['guard'], $narrowTo),
        ));

        if ($every) {
            // Reaching every site means asking each one, and PHPStan's own type for the parameter there is the
            // better answer where it has one: after `if ($e instanceof A) { return …; }` it says `$e` is
            // anything BUT an A, which no guard of required classes can spell.
            $admitted = array_values(array_filter(
                $satisfiable,
                fn (array $candidate): bool => $candidate['scope'] === null
                    || ! $candidate['scope']->getType(new Variable($param))->isSuperTypeOf(new ObjectType($narrowTo))->no(),
            ));

            return ['returns' => $this->returnSites($admitted, $callable), 'diagnostics' => []];
        }

        $chosen = $this->chooseNarrowedSite($satisfiable, $narrowTo);

        return [
            'returns' => $this->returnSites($chosen === null ? [] : [$chosen], $callable),
            'diagnostics' => $this->narrowingAmbiguity($satisfiable, $chosen, $narrowTo, $param, $callable),
        ];
    }

    /**
     * @param  list<NarrowedSite>  $sites
     * @return list<ReturnSite>
     */
    private function returnSites(array $sites, CallableRef $callable): array
    {
        return array_map(
            static fn (array $s): ReturnSite => new ReturnSite($s['type'], new SourceLocation($callable->file, $s['line']), $s['component'], $s['echoes'], $s['conditions']),
            $sites,
        );
    }

    /**
     * One returned expression as the branches it can take: a ternary's two, each in the scope its
     * condition leaves and followed down through nested ternaries; anything else, itself. The short
     * `?:` form is not expanded — its true branch is the condition, whose value this cannot type apart.
     *
     * @return list<array{Node\Expr, Scope}>
     */
    private function branches(Node\Expr $expr, Scope $scope): array
    {
        if (! $expr instanceof Node\Expr\Ternary || $expr->if === null) {
            return [[$expr, $scope]];
        }

        return [
            ...$this->branches($expr->if, $scope->filterByTruthyValue($expr->cond)),
            ...$this->branches($expr->else, $scope->filterByFalseyValue($expr->cond)),
        ];
    }

    /**
     * The guard the narrowed parameter's type states in `$scope` ({@see NarrowingGuard::ofType()}); none
     * where nothing is narrowed.
     *
     * @return list<list<string>>
     */
    private function paramGuard(?string $param, Scope $scope): array
    {
        return $param === null ? [] : NarrowingGuard::ofType($this->translator->translate($scope->getType(new Variable($param))));
    }

    /**
     * @param  list<list<string>>  $guard
     * @param  list<Node\Expr\MethodCall>  $probes
     * @return NarrowedSite
     */
    private function site(?Node\Expr $expr, Node $positioned, Scope $scope, array $guard, CallableBody $body, array $probes, bool $every, bool $typesParameter = true): array
    {
        $shape = $this->siteShape($expr, $scope);

        return [
            'pos' => SourceOrder::of($positioned),
            'line' => $positioned->getStartLine(),
            'type' => $shape['type'],
            'component' => $shape['component'],
            'guard' => $guard,
            'delegates' => $this->isDelegation($shape['type']),
            'echoes' => $every ? ParameterUse::echoed($expr, $body->parameters, $body->nodes) : null,
            'conditions' => $every ? ParameterUse::conditionsAt($scope, $probes) : [],
            'scope' => $typesParameter ? $scope : null,
        ];
    }

    /**
     * The first site in source order that either matches the guard exactly or produces a response; falls
     * back to the first satisfiable one for a genuinely all-delegating renderer.
     *
     * @param  list<NarrowedSite>  $satisfiable
     * @return NarrowedSite|null
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
     * @param  list<Node\Expr\MethodCall>  $probes
     * @return list<NarrowedSite>
     */
    private function matchArmSites(Node\Expr\Match_ $match, ?string $param, Scope $scope, CallableBody $body, array $probes, bool $every): array
    {
        $sites = [];
        foreach ($match->arms as $arm) {
            $guard = $arm->conds === null || $param === null ? [] : $this->armInstanceofGuards($arm->conds, $param, $scope);
            // The return's scope, not the arm's: it has not narrowed the parameter, so only the guard speaks.
            $sites[] = $this->site($arm->body, $arm->body, $scope, $guard, $body, $probes, $every, typesParameter: false);
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
     * @param  list<NarrowedSite>  $satisfiable
     * @param  NarrowedSite|null  $chosen
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
                $this->label($callable),
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
        $bodies = $this->classBodies ??= new AnalyzedBodies($this->fileAnalyzer);
        // Application scope for the two status READS, descend scope for the analyzer's own walk: how far
        // this build may descend is `project_paths`' question, and whether a declaration is the
        // application's is not. An exception class in a modular root is the application's, its file is
        // primed, and reading it grows no analysed set.
        $statuses = $this->httpExceptionStatus ??= new HttpExceptionStatus($bodies, $this->appFilter);

        return new ThrowAnalyzer(
            $this->adapter->reflectionProvider(),
            $this->projectFilter,
            $this->appFilter,
            $this->declaredFilter,
            $this->fileAnalyzer,
            $this->config->knownThrowers,
            new CalleeResolver($this->adapter->reflectionProvider()),
            $statuses,
            $this->factoryStatus ??= new FactoryStatus($statuses, $bodies, $this->appFilter),
            $this->labels,
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
            // Application scope, not $this->projectFilter: render helpers can live in any primed app
            // root (`Modules\…`), outside the descend scope the QB trace and throw descent walk by.
            // Vendor still never folds.
            $this->appFilter,
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
