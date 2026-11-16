<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\PayloadStatusT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\StatusTextMarkerT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\StatusCodes;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ContentTypeHeader;
use Docuccino\Inference\PhpStan\Support\FacadeCall;
use Docuccino\Inference\PhpStan\Support\MadeResponseCall;
use Docuccino\Inference\PhpStan\Support\OmissionSentinel;
use Docuccino\Inference\PhpStan\Support\PlainContent;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Support\ResponseFactoryCall;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use Docuccino\Inference\PhpStan\Support\TransportResponseCall;
use Docuccino\Inference\PhpStan\Throwing\AnalyzedBodies;
use Docuccino\Inference\PhpStan\Throwing\CaseStatus;
use Docuccino\Inference\PhpStan\Throwing\ConstantSource;
use Docuccino\Inference\PhpStan\Throwing\SubclassStatus;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ReturnStatement;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ReflectionClass;

/**
 * Recovers the real response shape when a handler builds its response through a project helper whose
 * declared return type erases it (`renderNotFound(): JsonResponse`): follows the call into the callee's
 * own return sites and substitutes the richer `JsonResponse<payload, status, contentType, members>`. A
 * response NAMED in a local before it goes out — the shape any renderer takes once it copies the headers
 * an exception carries onto it — is followed back to what built it ({@see refineLocal()}). Design detail:
 * see docs/design/inference-embedding.md §4a.
 *
 * Invariants: bounded by the engine's descent depth and per-analysis file budget; memoised per callee
 * `class::method`, with the memo bound-aware in BOTH directions — a truncated result is used once and
 * never cached, and a complete entry is only served to a caller with the depth and file budget to have
 * computed it itself, so a route's shape never depends on which unrelated route ran first; a callee's
 * shape is call-independent, so statuses and body members that read a parameter are recorded as accessors
 * and bound at the call site; each hop stamps the `#[ErrorComponent]` it declares over the one below it
 * ({@see declared()}); nothing is ever guessed — an unfoldable status stays permissive, and a
 * descent that ran out of bound says so via {@see takeTruncations()}; vendor code is never followed —
 * containment is the PRIME scope (every app PSR-4 root, including a modular `Modules\…` one), not the
 * narrower descend scope throws/QB-trace use; every file touched is reported via {@see takeFiles()} so
 * the fragment cache stays sound.
 *
 * @phpstan-import-type Restated from LocalRestatements
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

    /** The responses whose body is JSON; the rest of {@see RESPONSE_FQCNS} send their content as it is. */
    public const JSON_FQCNS = [
        'Illuminate\\Http\\JsonResponse',
        'Symfony\\Component\\HttpFoundation\\JsonResponse',
    ];

    /** Laravel's plain response, which morphs an array handed to it into JSON. */
    public const PLAIN_RESPONSE = 'Illuminate\\Http\\Response';

    /** What `view()` renders, and so the body of a response the factory makes of a view. */
    public const VIEW = 'Illuminate\\Contracts\\View\\View';

    /** The canonical FQCN the recovered shape is emitted under (the shape the pipeline unwraps). */
    public const CANONICAL_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    /** Memo + bound accounting: what a descent cost, and whether a caller can afford to be served it. */
    private readonly DescentBudget $budget;

    /** Folds accessors on a bound enum case (`->value`, `->name`, `->status()`) — the last hop. */
    private readonly EnumAccessorFolder $enumFolder;

    /** Reads the `#[ErrorComponent]` each descended hop declares for the body it answers with. */
    private readonly ComponentDeclarations $declarations;

    /** Peels `->setStatusCode(…)`/`->header(…)` off a response so the shape is read where it was built. */
    private readonly FluentResponseChain $fluentChain;

    /** Reads which members of an object body its constructor takes off its parameters. */
    private readonly ConstructorEchoes $echoes;

    /** Reads the status a response class the application derives hands its framework parent. */
    private readonly SubclassStatus $subclasses;

    /** …and the body and headers it hands it. */
    private readonly ParentArgument $subclassArguments;

    /**
     * The object whose application-written `toResponse()` is being read, and the class that wrote it: inside
     * that body, `parent::toResponse()` is the framework rendering THIS object ({@see parentRendering()}).
     *
     * @var array{class: string, receiver: ClassT}|null
     */
    private ?array $renderingSelf = null;

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly TypeTranslator $translator,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly CalleeResolver $calleeResolver,
        private readonly ProjectFilter $appFilter,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly CaseStatus $caseStatus,
        private readonly ClassMetadataFactory $classes = new ClassMetadataFactory,
        int $maxDepth = 4,
        int $fileBudget = 40,
    ) {
        $this->budget = new DescentBudget($maxDepth, $fileBudget);
        $this->declarations = new ComponentDeclarations($reflectionProvider);
        $this->echoes = new ConstructorEchoes($this->touchProject(...));
        $bodies = new AnalyzedBodies($fileAnalyzer, $caseStatus);
        $this->subclasses = SubclassStatus::ofResponses($bodies, $appFilter, self::roots());
        $this->fluentChain = new FluentResponseChain($reflectionProvider, $translator, $this->statusIn(...), $this->chainedRoot(...), $this->payloadOf(...));
        $this->subclassArguments = new ParentArgument($bodies, $appFilter);
        $this->enumFolder = new EnumAccessorFolder(
            $this->fileAnalyzer,
            $this->appFilter,
            function (string $file): void {
                $this->touch($file);
            },
        );
    }

    /**
     * Every framework response the refiner reads where it is built, which an application's own response
     * class derives from ({@see isResponseSubclass()}).
     *
     * @return list<string>
     */
    public static function roots(): array
    {
        return [...self::RESPONSE_FQCNS, ...TransportResponseCall::CLASSES];
    }

    /** Whether a FQCN is a bare response type worth enriching — the harvest's gate before descending. */
    public static function isResponseFqcn(string $fqcn): bool
    {
        return in_array($fqcn, self::RESPONSE_FQCNS, true);
    }

    /**
     * Whether a FQCN is a file, stream or redirect response: only its status is recovered, and it is
     * emitted as its own class ({@see RefinedResponse::$transport}).
     */
    public static function isTransportFqcn(string $fqcn): bool
    {
        return in_array($fqcn, TransportResponseCall::CLASSES, true);
    }

    /**
     * Whether a FQCN is a response class the application derives from one of the framework's, whose own
     * constructor may name its status ({@see SubclassStatus}). Only one written in the application's own
     * files: a package's constructor is not read, so its class stays the bare response it is typed as.
     */
    public function isResponseSubclass(string $fqcn): bool
    {
        $root = $this->subclasses->root($fqcn);
        $file = $root === null || $root === $fqcn || ! class_exists($fqcn) ? false : (new ReflectionClass($fqcn))->getFileName();

        return $file !== false && $this->appFilter->isProjectFile($file);
    }

    /**
     * The framework response a class is, or the one an application's subclass derives from
     * ({@see isResponseSubclass()}); null for anything the refiner does not read where it is built.
     */
    public function responseRoot(string $fqcn): ?string
    {
        return match (true) {
            self::isResponseFqcn($fqcn), self::isTransportFqcn($fqcn) => $fqcn,
            $this->isResponseSubclass($fqcn) => $this->subclasses->root($fqcn),
            default => null,
        };
    }

    /**
     * {@see responseRoot()} for a chain's receiver. What a link on an application's subclass does turns on
     * which class declares it, and an override can be added to any file of its hierarchy, so all are read.
     */
    private function chainedRoot(string $fqcn): ?string
    {
        $root = $this->responseRoot($fqcn);
        if ($root !== null && $root !== $fqcn) {
            foreach (DeclarationFiles::of($fqcn) as $file) {
                $this->touchProject($file);
            }
        }

        return $root;
    }

    /** Any kind of response the refiner reads where it was built. */
    private function isRefinable(string $fqcn): bool
    {
        return self::isResponseFqcn($fqcn) || self::isTransportFqcn($fqcn) || $this->isResponseSubclass($fqcn);
    }

    /** Null when nothing better than the bare type is recoverable. */
    public function refine(Node\Expr $expr, Scope $scope): ?RefinedResponse
    {
        return $this->refineExpr($expr, $scope, self::ownParameters($scope), 0);
    }

    /**
     * Every response a return site can be sent as: one, except where it reaches an application-written
     * `toResponse()` with several returns — a guard arm answering 410 beside `parent::toResponse()` is two
     * responses, and publishing either as the whole would state a contract the other breaks. Null when
     * nothing better than the bare type is recoverable, which includes an override with an arm that could
     * not be read: a subset is not the whole.
     *
     * @return non-empty-list<RefinedResponse>|null
     */
    public function refineArms(Node\Expr $expr, Scope $scope): ?array
    {
        return $this->armsOf($expr, $scope, 0, self::ownParameters($scope));
    }

    /**
     * The parameters of the function the analysed return is written in. Nothing calls that function from
     * inside the analysis, so an accessor on one never binds; what it still answers is whether two reads in
     * one body are the same value — how a member is known to echo the status sent beside it.
     *
     * @return list<string>
     */
    private static function ownParameters(Scope $scope): array
    {
        $closure = $scope->getAnonymousFunctionReflection();
        $parameters = $closure !== null
            ? $closure->getParameters()
            : ($scope->getFunction()?->getVariants()[0]->getParameters() ?? []);

        return array_map(static fn (ParameterReflection $parameter): string => $parameter->getName(), $parameters);
    }

    /**
     * @param  list<string>  $paramNames
     * @return non-empty-list<RefinedResponse>|null
     */
    private function armsOf(Node\Expr $expr, Scope $scope, int $depth, array $paramNames = []): ?array
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            $chain = $this->fluentChain->peel($expr, $scope);
            if ($chain !== null) {
                return ResponseArms::allLaid($this->armsOf($chain['receiver'], $scope, $depth, $paramNames) ?? [$this->bare($chain['receiver'], $scope)], $chain);
            }

            $render = $this->rendering($expr, $scope);
            if ($render !== null) {
                return $this->renderArms($render, $depth);
            }
        }

        $refined = $this->refineExpr($expr, $scope, $paramNames, $depth);

        return $refined === null ? null : [$refined];
    }

    /**
     * Whether this reads the expression better than the response type PHPStan resolved for it, so the
     * harvest knows a parameterised type is not the last word. Three shapes qualify: a `new` whose arguments
     * fold to more than the erased generic the stub's `@template` bounds leave behind, a fluent chain,
     * whose links can restate a status the receiver's own type carries unchanged
     * ({@see FluentResponseChain}), and a `response()->json()` whose status is a read on an enum case, which
     * its type carries as a bare int, or as every code where the cases disagree ({@see CaseStatus}). A local
     * qualifies where what it holds does, or where a statement restates it ({@see LocalRestatements}). A
     * factory call's status is read either way, so where it was written is recorded when the harvest keeps
     * the type.
     */
    public function outranksResolvedType(Node\Expr $expr, Scope $scope): bool
    {
        if ($expr instanceof Node\Expr\New_ || $this->fluentChain->peel($expr, $scope) !== null) {
            return true;
        }
        if ($expr instanceof Node\Expr\Variable) {
            // A local is read as what it was assigned, so it outranks its type wherever that would.
            $assigned = $this->assignment($expr, $scope);

            return $this->restated($expr, $scope) !== null || ($assigned !== null && $this->outranksResolvedType($assigned[0], $assigned[1]));
        }

        $status = $expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall ? $this->factoryStatus($expr, $scope) : null;
        if ($status === null) {
            return false;
        }

        $read = $this->statusIn($status, $scope);
        $typed = ScalarFold::statusOf($scope->getType($status));

        return ($read !== null && $typed === null) || ($typed !== null && $this->caseStatus->disagrees($status, $scope));
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
        // 0. A fluent tail on the response the code built (`->setStatusCode(202)`, `->header(…)`): the
        // shape belongs to the receiver and the chain only restates what it set, so refine the receiver
        // and apply the difference ({@see ResponseArms::laid()}).
        if ($expr instanceof Node\Expr\MethodCall) {
            $chain = $this->fluentChain->peel($expr, $scope);
            if ($chain !== null) {
                return ResponseArms::laid($this->refineExpr($chain['receiver'], $scope, $paramNames, $depth) ?? $this->bare($chain['receiver'], $scope), $chain);
            }
        }

        // 0b. A local whose later statements restate what the chain would have (`$r->setStatusCode(202);`):
        // the response as it was built, with those statements laid over it in the order they ran.
        if ($expr instanceof Node\Expr\Variable) {
            $restated = $this->restated($expr, $scope);
            if ($restated !== null) {
                return $this->restate($this->refineBuilt($expr, $scope, $paramNames, $depth) ?? $this->bare($expr, $scope), $restated, $expr);
            }
        }

        return $this->refineBuilt($expr, $scope, $paramNames, $depth);
    }

    /**
     * The response as the expression builds it, before anything after it restates a part of it.
     *
     * @param  list<string>  $paramNames
     */
    private function refineBuilt(Node\Expr $expr, Scope $scope, array $paramNames, int $depth): ?RefinedResponse
    {
        // 1. `new JsonResponse($body, $status, [headers])` — fold the constructor arguments directly — or
        // `new GoneResponse(…)`, whose own constructor hands its framework parent the status and body.
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $class = $scope->resolveName($expr->class);
            if (self::isResponseFqcn($class)) {
                return $this->foldConstructor($expr, $class, $scope, $paramNames);
            }
            if ($this->isResponseSubclass($class)) {
                return $this->foldSubclass($expr, $class, $scope, $paramNames);
            }
        }

        // 1b. A plain response the factory makes (`response('', 410)`, `response()->make(…)`, `->view(…)`).
        $made = MadeResponseCall::of($expr, $scope, $this->reflectionProvider);
        if ($made !== null) {
            return $this->foldMade($made, $scope, $paramNames);
        }

        // 1c. A file, stream or redirect the framework makes (`response()->stream($cb, 202)`, `back(303)`,
        // `new StreamedResponse($cb, 206)`): its status, and nothing of a body the class and its call describe.
        $transport = TransportResponseCall::of($expr, $scope, $this->reflectionProvider);
        if ($transport !== null) {
            return $this->foldTransport($transport, $scope, $paramNames);
        }

        // 2. A response built into a local and then returned (`$r = Problem::make(…); …; return $r;`) — the
        // idiomatic shape whenever the protocol headers the exception carries have to be copied onto the
        // response before it goes out. The variable's own type is the bare class, or the erased generic of a
        // `new JsonResponse(…)`, so the shape lives in what was assigned to it ({@see refineLocal()}).
        $type = $this->translator->translate($scope->getType($expr));
        if ($expr instanceof Node\Expr\Variable && $type instanceof ClassT && $this->isRefinable($type->fqcn)) {
            $local = $this->refineLocal($expr, $scope, $paramNames, $depth);
            if ($local !== null || $type->typeArgs === []) {
                return $local;
            }
        }

        // 3. Type system already carries the shape (`response()->json([...], 422)`, via our extension).
        if ($type instanceof ClassT && self::isResponseFqcn($type->fqcn) && $type->typeArgs !== []) {
            $refined = $this->fromTypeArgs($type);

            return $expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall ? $this->factoryAccessors($refined, $expr, $scope, $paramNames) : $refined;
        }

        // 4. A call into project code — a method or a function — whose declared return erased the shape:
        // descend and substitute. A Responsable rendering itself (`$resource->response()`) is answered by
        // whoever wrote its `toResponse()` ({@see rendering()}), and so is `parent::toResponse()` inside the
        // application's own ({@see parentRendering()}). One shape only: an override with several arms is
        // several responses, which only {@see refineArms()} can carry.
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall || $expr instanceof Node\Expr\FuncCall) {
            if ($type instanceof ClassT && $this->isRefinable($type->fqcn)) {
                $render = $expr instanceof Node\Expr\MethodCall ? $this->rendering($expr, $scope) : null;
                if ($render !== null) {
                    return ResponseArms::single($this->renderArms($render, $depth, $paramNames));
                }
                if ($expr instanceof Node\Expr\StaticCall) {
                    $self = $this->parentRendering($expr, $scope, $depth);
                    if ($self !== false) {
                        return $self;
                    }
                }
                if (! $this->budget->withinDepth($depth + 1)) {
                    $this->budget->truncate(); // depth cutoff — the enclosing shape is truncated

                    return null;
                }
                $callee = $this->calleeResolver->resolve($expr, $scope);
                if ($callee !== null && $this->appFilter->isProjectFile($callee->file)) {
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

        // 5. A `return null` / void arm — the renderer delegates this type to the framework.
        if ($type instanceof NullT || $type instanceof VoidT) {
            return RefinedResponse::delegation();
        }

        return null;
    }

    /**
     * Every response a returned Responsable is sent as when the APPLICATION wrote its `toResponse()` — the
     * router renders a returned object through that method, so the class's own shape is not what goes out.
     * Null when the framework's `toResponse()` is what runs (the adapter renders the class itself, which is
     * exactly what that body sends), or when the value is not one such object at all.
     *
     * An override this cannot read in full degrades to the method's declared response class rather than to
     * the object's own shape: vague, but a description of what is sent.
     *
     * @return non-empty-list<DType>|null
     */
    public function renderedByOverride(Node\Expr $expr, Scope $scope): ?array
    {
        $render = $this->rendering(new Node\Expr\MethodCall($expr, new Node\Identifier(ResponsableRendering::TO_RESPONSE)), $scope);
        if ($render === null || $render['callee'] === null) {
            return null;
        }

        return ResponseArms::types($this->renderArms($render, 0), self::CANONICAL_RESPONSE);
    }

    /**
     * Who answers for a Responsable asked to render itself — `$resource->response()`, or `->toResponse($r)`
     * on any Responsable ({@see ResponsableRendering}): the object as the receiver's type, plus the
     * application's `toResponse()` to read when it wrote one (null when the framework's is what runs). Null
     * for anything else, and for a union receiver, which has no one body.
     *
     * Either answer holds only while no CLOSER override is written, and one can be added to any project file
     * of the object's hierarchy — the class, a parent between it and the one that wrote it, a trait — so all
     * of them are touched, and none is otherwise a file this recovery read.
     *
     * @return array{receiver: ClassT, callee: Callee|null, call: Node\Expr\MethodCall, scope: Scope}|null
     */
    private function rendering(Node\Expr\MethodCall $call, Scope $scope): ?array
    {
        if (! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
            return null;
        }

        $classes = $scope->getType($call->var)->getObjectClassNames();
        $receiver = $this->translator->translate($scope->getType($call->var));
        if (count($classes) !== 1 || ! $receiver instanceof ClassT || ! $this->reflectionProvider->hasClass($classes[0])) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($classes[0])->getNativeReflection();
        $owner = ResponsableRendering::of($class, $call->name->toString(), $this->appFilter->isProjectFile(...));
        if ($owner === null) {
            return null;
        }

        foreach (ResponsableRendering::projectFiles($class, $this->appFilter->isProjectFile(...)) as $file) {
            $this->touch($file);
        }

        if ($owner === ResponsableRendering::FRAMEWORK) {
            return ['receiver' => $receiver, 'callee' => null, 'call' => $call, 'scope' => $scope];
        }

        $render = new Node\Expr\MethodCall($call->var, new Node\Identifier(ResponsableRendering::TO_RESPONSE), $call->args);
        $callee = $this->calleeResolver->resolve($render, $scope);

        return $callee === null ? null : ['receiver' => $receiver, 'callee' => $callee, 'call' => $render, 'scope' => $scope];
    }

    /**
     * The responses one rendering is sent as: the object itself for the framework's rendering, or each
     * return of the application's `toResponse()`, read with that object as `$this`. Not memoised: an
     * inherited override answers for whichever object it renders, so its shape is not the method's alone.
     * Null when any return cannot be read — publishing the others as the whole would leave the missing one
     * out of the contract.
     *
     * Each arm is then bound to the call that reached it, as a descended helper's shape is
     * ({@see bindCall()}), and stamped with the `#[ErrorComponent]` the override declares. Where the object
     * was built in sight, its promoted properties hold what that construction passed ({@see constructionHolds()}).
     *
     * @param  array{receiver: ClassT, callee: Callee|null, call: Node\Expr\MethodCall|Node\Expr\StaticCall, scope: Scope}  $render
     * @param  list<string>  $paramNames  the caller's parameter names
     * @return non-empty-list<RefinedResponse>|null
     */
    private function renderArms(array $render, int $depth, array $paramNames = []): ?array
    {
        $callee = $render['callee'];
        if ($callee === null) {
            return [RefinedResponse::renderedBy($render['receiver'])];
        }
        $class = $callee->class;
        if ($class === null) {
            return null; // a `toResponse()` call resolves to a method, never a function
        }

        if (! $this->budget->withinDepth($depth + 1) || ! $this->budget->withinBudget($this->adapter->normalize($callee->file))) {
            $this->budget->truncate();

            return null;
        }
        $this->touch($callee->file);
        $this->touch($callee->writtenIn());

        $node = $this->fileAnalyzer->callee($callee);
        if ($node === null) {
            return null;
        }

        $outer = $this->renderingSelf;
        $this->renderingSelf = ['class' => $class, 'receiver' => $render['receiver']];
        // `parent::toResponse()` renders `$this`, whose construction is not in sight from here.
        $held = $render['call'] instanceof Node\Expr\MethodCall
            ? $this->constructionHolds($render['call']->var, $render['scope'], $render['receiver']->fqcn)
            : [];
        $read = [];
        try {
            foreach ($node->getReturnStatements() as $statement) {
                $expr = $statement->getReturnNode()->expr;
                $returnScope = $this->fileAnalyzer->stableScope($statement->getScope());
                foreach ($held as [$property, $type]) {
                    $returnScope = $this->adapter->holding($returnScope, $property, $type);
                }
                $refined = $expr === null
                    ? null
                    : $this->refineExpr($expr, $returnScope, $this->parameterNames($callee), $depth + 1);
                if ($expr === null || $refined === null || $refined->delegates) {
                    $read[] = null;

                    continue;
                }
                $refined = $refined->contentType === null
                    ? self::labelledContentType($refined, $expr, $node->getStatements())
                    : $refined;
                // The framework's own rendering of the object carries nothing the call could bind.
                $declared = $this->declared($refined, $callee) ?? $refined;
                $read[] = $declared->statusOfPayload ? $declared : $this->bindCall($declared, $callee, $render['call'], $render['scope'], $paramNames);
            }
        } finally {
            $this->renderingSelf = $outer;
        }

        return ResponseArms::whole($read);
    }

    /**
     * What each readonly property promoted from the object's constructor holds inside the object's own
     * methods ({@see HeldArguments::promotedReadonly()}): the type of the argument the construction passed
     * it, or the parameter's default where it provably passed none. The construction is the receiver itself
     * when it is a `new` of the class, or the one `new` a project factory returns, read with the factory's
     * parameters holding what this call passed them. Empty for anything else, and the body is then read
     * with each property's declared type.
     *
     * @return list<array{Node\Expr\PropertyFetch, Type}>
     */
    private function constructionHolds(Node\Expr $receiver, Scope $scope, string $fqcn): array
    {
        $construction = $this->construction($receiver, $scope, $fqcn);
        if ($construction === null || $construction[0]->isFirstClassCallable() || ! $this->reflectionProvider->hasClass($fqcn)) {
            return [];
        }
        [$new, $newScope] = $construction;

        $class = $this->reflectionProvider->getClass($fqcn);
        if (! $class->hasConstructor()) {
            return [];
        }

        $promoted = HeldArguments::promotedReadonly($class->getNativeReflection());
        $held = [];
        foreach (self::argumentTypes($class->getConstructor()->getVariants()[0]->getParameters(), $new->getArgs(), $newScope) as $name => $type) {
            if (in_array($name, $promoted, true)) {
                $held[] = [new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name), $type];
            }
        }

        return $held;
    }

    /**
     * The `new` of `$fqcn` a receiver is, or the only return of the project factory it calls, with the scope
     * to read its arguments in. A factory with any other return builds more than one object, and none of
     * them is this one.
     *
     * @return array{Node\Expr\New_, Scope}|null
     */
    private function construction(Node\Expr $receiver, Scope $scope, string $fqcn): ?array
    {
        if ($receiver instanceof Node\Expr\New_) {
            return $receiver->class instanceof Node\Name && $scope->resolveName($receiver->class) === $fqcn ? [$receiver, $scope] : null;
        }

        if ((! $receiver instanceof Node\Expr\MethodCall && ! $receiver instanceof Node\Expr\StaticCall && ! $receiver instanceof Node\Expr\FuncCall)
            || $receiver->isFirstClassCallable()
        ) {
            return null;
        }

        $factory = $this->calleeResolver->resolve($receiver, $scope);
        if ($factory === null || ! $this->appFilter->isProjectFile($factory->file)) {
            return null;
        }
        if (! $this->budget->withinBudget($this->adapter->normalize($factory->file))) {
            $this->budget->truncate();

            return null;
        }
        $this->touch($factory->file);
        $this->touch($factory->writtenIn());

        $returns = $this->fileAnalyzer->callee($factory)?->getReturnStatements() ?? [];
        $new = HeldArguments::soleConstruction(array_map(static fn (ReturnStatement $return): ?Node\Expr => $return->getReturnNode()->expr, $returns));
        $factoryScope = $new === null ? null : $this->fileAnalyzer->stableScope($returns[0]->getScope());
        if ($new === null || $factoryScope === null || ! $new->class instanceof Node\Name || $factoryScope->resolveName($new->class) !== $fqcn) {
            return null;
        }

        foreach (self::argumentTypes($factory->parameters($this->reflectionProvider), $receiver->getArgs(), $scope) as $name => $type) {
            $factoryScope = $this->adapter->holding($factoryScope, new Node\Expr\Variable($name), $type);
        }

        return [$new, $factoryScope];
    }

    /**
     * The type each parameter a call settles holds ({@see HeldArguments::of()}): the argument's, read where
     * the call is written, or the parameter's default.
     *
     * @param  list<ParameterReflection>  $parameters
     * @param  array<Node\Arg>  $args
     * @return array<string, Type>
     */
    private static function argumentTypes(array $parameters, array $args, Scope $scope): array
    {
        $declared = [];
        foreach ($parameters as $parameter) {
            $declared[$parameter->getName()] = $parameter;
        }

        $types = [];
        foreach (HeldArguments::of(HeldArguments::parameters($parameters), $args) as $name => $arg) {
            $type = $arg === null ? $declared[$name]->getDefaultValue() : $scope->getType($arg);
            if ($type !== null) {
                $types[$name] = $type;
            }
        }

        return $types;
    }

    /**
     * `parent::toResponse($request)` inside the application's `toResponse()` of the object being rendered:
     * the framework's own rendering of that object, when the parent's method is the framework's — or the
     * parent's own override read the same way, when the application wrote that too. `false` when the call is
     * not one of these, so the caller carries on; null when it is and could not be read.
     */
    private function parentRendering(Node\Expr\StaticCall $call, Scope $scope, int $depth): RefinedResponse|false|null
    {
        $self = $this->renderingSelf;
        if ($self === null || ! ResponseArms::isParentRendering($call, $scope->getClassReflection()?->getName(), $self['class'])) {
            return false;
        }

        $callee = $this->calleeResolver->resolve($call, $scope);
        if ($callee === null) {
            return null;
        }
        if (! $this->appFilter->isProjectFile($callee->file)) {
            return RefinedResponse::renderedBy($self['receiver']);
        }

        return ResponseArms::single($this->renderArms(['receiver' => $self['receiver'], 'callee' => $callee, 'call' => $call, 'scope' => $scope], $depth));
    }

    /**
     * The shape of the expression a returned local was assigned, read in the scope it was assigned in.
     *
     * Deliberately narrow, because a wrong answer here is a body the endpoint never sends: only a local
     * the method assigns EXACTLY ONCE ({@see FileAnalyzer::localAssignments()}) — two branches writing one
     * variable are not described by either — never one assigned from another local, which says nothing
     * this call has not already asked, and only for a read written after the assignment ({@see assignment()}).
     *
     * The depth is the caller's: naming a value is not a call hop, and the descent the assignment may
     * itself be counts its own.
     *
     * @param  list<string>  $paramNames
     */
    private function refineLocal(Node\Expr\Variable $local, Scope $scope, array $paramNames, int $depth): ?RefinedResponse
    {
        $assignment = $this->assignment($local, $scope);

        return $assignment === null ? null : $this->refineExpr($assignment[0], $assignment[1], $paramNames, $depth);
    }

    /**
     * The one expression a local is assigned ({@see refineLocal()}), and the scope it is assigned in. Null for
     * a read written before the assignment ends: `$response` inside `$response = $response->header(…)` is the
     * response the local held before, a parameter's or nothing, which this assignment does not describe. So
     * each local followed lies wholly before the read that followed it, and no chain of them comes back round.
     *
     * @return array{Node\Expr, Scope}|null
     */
    private function assignment(Node\Expr\Variable $local, Scope $scope): ?array
    {
        $key = FileAnalyzer::scopeKey($scope);
        if ($key === null || ! is_string($local->name)) {
            return null;
        }

        $assignment = $this->fileAnalyzer->localAssignments($scope->getFile())[$key][$local->name] ?? null;
        if ($assignment === null
            || $assignment[0] instanceof Node\Expr\Variable
            || $assignment[0]->getEndFilePos() >= $local->getStartFilePos()
        ) {
            return null;
        }

        return [$assignment[0], $this->fileAnalyzer->stableScope($assignment[1])];
    }

    /**
     * What the statements ahead of a returned local restate about the response it holds
     * ({@see LocalRestatements}), or null where they restate nothing. A parameter, or `$this`, holds a
     * response someone else built, which is not the one this reads.
     *
     * @return Restated|null
     */
    private function restated(Node\Expr\Variable $local, Scope $scope): ?array
    {
        if (! is_string($local->name) || $local->getStartFilePos() < 0 || in_array($local->name, ['this', ...self::ownParameters($scope)], true)) {
            return null;
        }
        $type = $this->translator->translate($scope->getType($local));
        if (! $type instanceof ClassT || ! $this->isRefinable($type->fqcn)) {
            return null;
        }

        $file = $scope->getFile();
        $body = $this->fileAnalyzer->bodyAround($file, $local->getStartFilePos());
        if ($body === null) {
            return null;
        }

        $restatements = new LocalRestatements(
            function (Node\Expr\MethodCall $call) use ($file): ?array {
                $at = $this->fileAnalyzer->scopeAtCall($file, $call);

                return $at === null ? null : $this->fluentChain->peel($call, $at);
            },
            fn (int $at, ?Node\Expr\MethodCall $madeOn): bool => $this->fileAnalyzer->keepsAt($file, $at, $madeOn, $this->touchProject(...)),
        );

        return $restatements->before($body, $local->name, $local->getStartFilePos());
    }

    /**
     * A response with what later statements restated laid over it. A status set only on some paths joins the
     * one the response already had, which therefore has to be one this read: a status a parameter or the
     * payload decides cannot be joined, and is unknown.
     *
     * @param  Restated  $restated
     */
    private function restate(RefinedResponse $built, array $restated, Node\Expr\Variable $local): ?RefinedResponse
    {
        [$status, $unknown] = [$restated['status'], $restated['statusUnknown']];
        if ($restated['mayAlsoBe'] !== [] && ! $unknown) {
            $before = $status ?? ($built->statusSource === null && ! $built->statusOfPayload ? $built->status : null);
            $joined = $before === null ? null : UnionT::of([$before, ...$restated['mayAlsoBe']]);
            [$status, $unknown] = $joined instanceof LiteralT || $joined instanceof UnionT ? [$joined, false] : [null, true];
        }

        return ResponseArms::laid($built, [
            'receiver' => $local,
            'status' => $status,
            'statusUnknown' => $unknown,
            'contentType' => $restated['contentType'],
            'contentTypeUnknown' => $restated['contentTypeUnknown'],
            'body' => $restated['body'],
            'bodyUnknown' => $restated['bodyUnknown'],
        ]);
    }

    /**
     * Fold `new JsonResponse($body, $status, [headers])`: payload from arg 0, status from arg 1 (literal,
     * pass-through parameter, or permissive), content type from a `Content-Type` header in arg 2. A plain
     * response's content is read as every plain response's is ({@see plainBody()}). Slots,
     * not written arguments ({@see ArgumentSlots}), so a named argument still lands on its parameter and a
     * spread nobody can read leaves every argument it covers UNKNOWN rather than absent.
     *
     * @param  list<string>  $paramNames  the current function's parameter names
     */
    private function foldConstructor(Node\Expr\New_ $new, string $class, Scope $scope, array $paramNames): RefinedResponse
    {
        $args = $new->isFirstClassCallable()
            ? ArgumentSlots::of([])
            : ArgumentSlots::of($new->getArgs(), $this->constructorParameterNames($class));

        $headers = $args->at(2);
        $contentType = $headers === null ? null : ContentTypeHeader::inArray($headers, $scope);

        $payload = null;
        [$provenance, $statusTexts] = [[], []];
        $body = $args->at(0);
        if (! in_array($class, self::JSON_FQCNS, true)) {
            [$payload, $contentType] = $this->plainBody($this->contentOf($args, 0, $scope), $contentType, $class);
        } elseif ($body !== null) {
            $payload = $this->payloadOf($scope->getType($body));
        }
        if ($body !== null) {
            [$provenance, $statusTexts] = $this->bodyProvenance($body, $scope, $paramNames);
        }

        // Symfony's 200 is what a call that provably passed NO status gets. A status sitting in a spread
        // this build cannot read is an unknown one, and publishing 200 there states a status the endpoint
        // may never send.
        $statusArg = $args->at(1);
        [$status, $statusSource] = match (true) {
            $statusArg !== null => $this->resolveStatus($statusArg, $scope, $paramNames),
            $args->knows(1) => [new LiteralT(200), null],
            default => [null, null],
        };

        // A member reading the same accessor as the status echoes the status: the factory marks it, so a
        // call site folding the status folds the member too, and an unfolded one still fills at doc time.
        $refined = RefinedResponse::fromConstructor($payload, $status, $statusSource, $contentType, $provenance, $statusTexts);

        if ($body === null || ! $payload instanceof ClassT) {
            return $refined;
        }

        $echoes = $this->constructedEchoes($body, $payload, $statusSource, $scope, $paramNames)->fields ?? [];
        $echoed = array_map(static fn (ArrayShapeField $field): string => (string) $field->key, $echoes);
        $fixed = array_values(array_filter(
            $this->constructedValues($body, $payload, $scope),
            static fn (ArrayShapeField $field): bool => ! in_array((string) $field->key, $echoed, true),
        ));
        $members = [...$echoes, ...$fixed];

        return $members === [] ? $refined : $refined->withPayloadMembers(new ArrayShapeT($members), []);
    }

    /**
     * Fold a plain response the factory makes ({@see MadeResponseCall}), its status read as the constructor's
     * is and its content as {@see plainBody()} reads it. Where that leaves nothing but the framework's 200 and
     * a body nothing read, the bare class already says it, so null.
     *
     * @param  array{view: bool, slots: ArgumentSlots, status: int, headers: int}  $made
     * @param  list<string>  $paramNames
     */
    private function foldMade(array $made, Scope $scope, array $paramNames): ?RefinedResponse
    {
        $args = $made['slots'];
        $statusArg = $args->at($made['status']);
        [$status, $statusSource] = match (true) {
            $statusArg !== null => $this->resolveStatus($statusArg, $scope, $paramNames),
            $args->knows($made['status']) => [new LiteralT(200), null],
            default => [null, null],
        };

        $headers = $args->at($made['headers']);
        $contentType = $headers === null ? null : ContentTypeHeader::inArray($headers, $scope);

        [$payload, $contentType] = $made['view']
            ? [new ClassT(self::VIEW), $contentType]
            : $this->plainBody($this->contentOf($args, 0, $scope), $contentType, self::PLAIN_RESPONSE);

        if ($payload === null && $contentType === null && $statusSource === null && $status instanceof LiteralT && $status->value === 200) {
            return null;
        }

        $refined = RefinedResponse::fromConstructor($payload, $status, $statusSource, $contentType, []);

        // A status stated and unread replaced the 200 the bare class would publish.
        return $status === null && $statusSource === null ? $refined->withUnreadStatus() : $refined;
    }

    /**
     * Fold a file, stream or redirect response ({@see TransportResponseCall}), its status read as the
     * constructor's is. One sent with the signature's own default — a call taking no status, passed none, or
     * passed that default — is the call the bare class already describes, so null.
     *
     * @param  array{class: string, slots: ArgumentSlots, status: int|null, default: int|null}  $call
     * @param  list<string>  $paramNames
     */
    private function foldTransport(array $call, Scope $scope, array $paramNames): ?RefinedResponse
    {
        $position = $call['status'];
        $statusArg = $position === null ? null : $call['slots']->at($position);
        if ($statusArg === null && ($position === null || $call['slots']->knows($position))) {
            return null;
        }

        [$status, $statusSource] = $statusArg === null ? [null, null] : $this->resolveStatus($statusArg, $scope, $paramNames);
        if ($status instanceof LiteralT && $status->value === $call['default']) {
            return null;
        }
        $refined = RefinedResponse::transported($call['class'], $status, $statusSource);

        // A status stated and unread replaced the default the bare class would publish.
        return $status === null && $statusSource === null ? $refined->withUnreadStatus() : $refined;
    }

    /**
     * Fold `new GoneResponse(…)`, a response class the application derives from the framework's: the status
     * its constructors hand the framework's ({@see SubclassStatus}) — fixed by the class, or a parameter of
     * its own constructor filled here, as a `throw new` fills an exception's — and, for a JSON or plain
     * response, the body and media type they hand it ({@see ParentArgument}). A class that states nothing
     * this can read is left the bare class it is typed as, and so is a file, stream or redirect sent with its
     * root's default.
     *
     * @param  list<string>  $paramNames  the current function's parameter names
     */
    private function foldSubclass(Node\Expr\New_ $new, string $class, Scope $scope, array $paramNames): ?RefinedResponse
    {
        $root = $this->subclasses->root($class) ?? $class;
        foreach ($this->subclasses->filesFor($class) as $file) {
            $this->touchProject($file);
        }

        $args = $new->isFirstClassCallable()
            ? ArgumentSlots::of([])
            : ArgumentSlots::of($new->getArgs(), $this->constructorParameterNames($class));

        $stated = $this->subclassStatus($class, $args, $scope, $paramNames);
        if ($stated === null) {
            return null;
        }
        [$status, $statusSource] = $stated;

        if (self::isTransportFqcn($root)) {
            if ($status instanceof LiteralT && $status->value === $this->subclasses->rootDefault($root)) {
                return null;
            }
            $refined = RefinedResponse::transported($class, $status, $statusSource);

            return $status === null && $statusSource === null ? $refined->withUnreadStatus() : $refined;
        }

        [$payload, $provenance, $statusTexts, $contentType] = $this->subclassBody($class, $root, $args, $scope, $paramNames);

        // As for the factory's plain response ({@see foldMade()}): its default status over content nothing
        // read is what the bare class already says.
        if ($payload === null && $statusSource === null && $contentType === null && ! in_array($root, self::JSON_FQCNS, true)
            && $status instanceof LiteralT && $status->value === $this->subclasses->rootDefault($root)
        ) {
            return null;
        }

        return RefinedResponse::fromConstructor($payload, $status, $statusSource, $contentType, $provenance, $statusTexts);
    }

    /**
     * The status a construction of a derived response is sent with, or the parameter of the enclosing
     * function it reads; null where the class states nothing. The class's slot is read at this call exactly
     * as the root's own constructor's would be — a literal, a constant, a read on an enum case, a choice —
     * through whatever read the class applies to it (`$fault->statusCode()`); a slot left empty takes the
     * class's default.
     *
     * @param  list<string>  $paramNames
     * @return array{LiteralT|UnionT|null, ParamAccessor|null}|null
     */
    private function subclassStatus(string $class, ArgumentSlots $args, Scope $scope, array $paramNames): ?array
    {
        $pinned = $this->subclasses->pinned($class);
        if ($pinned !== null) {
            return [new LiteralT($pinned), null];
        }

        $slot = $this->subclasses->statusParameter($class);
        if ($slot === null) {
            return null;
        }

        $argument = $args->at($slot);
        if ($argument === null) {
            $default = $args->knows($slot) ? $this->constructorDefault($class, $slot) : null;

            return [ScalarFold::statusOf($default), null];
        }

        $read = $this->subclasses->statusRead($class);
        if ($read === null) {
            return $this->resolveStatus($argument, $scope, $paramNames);
        }

        $codes = $this->statusIn($argument, $scope, $read);

        return [$codes, $codes === null ? $this->rehomeAccessor($argument, $read, $paramNames) : null];
    }

    /**
     * The body a derived JSON or plain response is sent with, and its media type: what its constructors fix,
     * typed where it is written, or what this construction passes to the parameter they hand through, or that
     * parameter's default. A plain response's content is read as every plain response's is
     * ({@see plainBody()}). Nothing, where neither.
     *
     * @param  list<string>  $paramNames
     * @return array{?DType, array<string, ParamAccessor>, array<string, array{ParamAccessor, ?LiteralT}>, ?string}
     */
    private function subclassBody(string $class, string $root, ArgumentSlots $args, Scope $scope, array $paramNames): array
    {
        $contentType = $this->subclassContentType($class, $root, $args, $scope);
        $landing = $this->subclassArguments->body($class, $root);
        $body = $landing === null || isset($landing['expr']) ? null : $args->at($landing['parameter']);
        $type = match (true) {
            $landing === null => null,
            isset($landing['expr']) => $this->fileAnalyzer->scopeAtCall($landing['file'], $landing['call'])?->getType($landing['expr']),
            $body !== null => $scope->getType($body),
            $args->knows($landing['parameter']) => $this->constructorDefault($class, $landing['parameter']),
            default => null,
        };

        if (! in_array($root, self::JSON_FQCNS, true)) {
            [$payload, $contentType] = $this->plainBody($type, $contentType, $root);
        } else {
            // A JSON response handed no data sends `{}`, which no `null` describes.
            $payload = $type === null || $type->isNull()->yes() ? null : $this->payloadOf($type);
        }

        return [$payload, ...($body === null ? [[], []] : $this->bodyProvenance($body, $scope, $paramNames)), $contentType];
    }

    /**
     * What a plain response sends for its content, and the media type it is labelled with ({@see PlainContent}):
     * no body, JSON under the default label whatever header was stated, a rendered view, or text under the
     * stated label or Symfony's `text/html`. Content of no one kind is an open body: under any media type where
     * it may be JSON, and under the label text would have where it may not.
     *
     * @param  ?Type  $content  null where nothing can see it
     * @param  string  $class  the framework response it is handed to
     * @return array{?DType, ?string}
     */
    private function plainBody(?Type $content, ?string $stated, string $class): array
    {
        $morphs = $class === self::PLAIN_RESPONSE;

        return match (PlainContent::of($content, $morphs)) {
            PlainContent::EMPTY => [new VoidT, null],
            PlainContent::JSON => [$content === null ? null : $this->payloadOf($content), null],
            PlainContent::RENDERED => [new ClassT(self::VIEW), $stated],
            PlainContent::TEXT => [$content === null ? null : PlainContent::text($content), $stated ?? PlainContent::DEFAULT_MEDIA_TYPE],
            PlainContent::UNREAD => PlainContent::mayBeJson($content, $morphs)
                ? [null, PlainContent::ANY_MEDIA_TYPE]
                : [null, $stated ?? PlainContent::DEFAULT_MEDIA_TYPE],
        };
    }

    /** The type of the content in one slot: `''` where the call provably passed none, null where a spread may hold it. */
    private function contentOf(ArgumentSlots $args, int $slot, Scope $scope): ?Type
    {
        $content = $args->at($slot);
        if ($content !== null) {
            return $scope->getType($content);
        }

        return $args->knows($slot) ? new ConstantStringType('') : null;
    }

    /**
     * The media type a `Content-Type` header names in the headers a derived response hands the framework's —
     * fixed by its constructors, or passed by this construction — read as `new JsonResponse()`'s are.
     */
    private function subclassContentType(string $class, string $root, ArgumentSlots $args, Scope $scope): ?string
    {
        $landing = $this->subclassArguments->headers($class, $root);
        if ($landing === null) {
            return null;
        }

        if (isset($landing['expr'])) {
            $at = $this->fileAnalyzer->scopeAtCall($landing['file'], $landing['call']);

            return $at === null ? null : ContentTypeHeader::inArray($landing['expr'], $at);
        }

        $headers = $args->at($landing['parameter']);

        return $headers === null ? null : ContentTypeHeader::inArray($headers, $scope);
    }

    /** The default a class's constructor gives one parameter, as the analyser reads the declaration. */
    private function constructorDefault(string $class, int $index): ?Type
    {
        if (! $this->reflectionProvider->hasClass($class) || ! $this->reflectionProvider->getClass($class)->hasConstructor()) {
            return null;
        }

        $parameters = $this->reflectionProvider->getClass($class)->getConstructor()->getVariants()[0]->getParameters();

        return ($parameters[$index] ?? null)?->getDefaultValue();
    }

    /** What a chain's receiver is when nothing was recovered of it: nothing, but of its own class. */
    private function bare(Node\Expr $receiver, Scope $scope): RefinedResponse
    {
        $type = $this->translator->translate($scope->getType($receiver));
        $root = $type instanceof ClassT ? $this->responseRoot($type->fqcn) : null;

        return $type instanceof ClassT && $root !== null && self::isTransportFqcn($root)
            ? RefinedResponse::transported($type->fqcn, null, null)
            : new RefinedResponse;
    }

    /**
     * {@see exactValues()} of an object body built right here, or in a local, since a readonly value no later
     * statement can change.
     *
     * @return list<ArrayShapeField>
     */
    private function constructedValues(Node\Expr $body, ClassT $payload, Scope $scope): array
    {
        if ($body instanceof Node\Expr\Variable && is_string($body->name)) {
            $body = ($this->locals($scope))($body->name) ?? $body;
        }

        return $body instanceof Node\Expr\New_ ? $this->exactValues($body, $payload->fqcn, $scope) : [];
    }

    /**
     * The members an object built as exactly `$fqcn` holds one value in ({@see ClassMetadataFactory::exactValues()}):
     * built by its name, `self` or `parent`, so no subclass's constructor ran, whether or not the class is
     * final. `new static` may be a subclass, and says nothing; nor does a class sent as anything but its
     * public properties.
     *
     * @return list<ArrayShapeField>
     */
    private function exactValues(Node\Expr\New_ $new, string $fqcn, Scope $scope): array
    {
        if (! $new->class instanceof Node\Name
            || $new->class->toLowerString() === 'static'
            || $scope->resolveName($new->class) !== $fqcn
            || ConstructorEchoes::ownJson($fqcn)
        ) {
            return [];
        }

        $exact = $this->classes->exactValues($fqcn);
        if ($exact['values'] === []) {
            return [];
        }

        // Read off the constructors and the properties the hierarchy declares, and any constant copied.
        foreach ([...DeclarationFiles::of($fqcn), ...$exact['files']] as $read) {
            $this->touchProject($read);
        }

        $fields = [];
        foreach ($exact['values'] as $member => $value) {
            $fields[] = new ArrayShapeField($member, $value);
        }

        return $fields;
    }

    /**
     * The members of an object body built right here (`new JsonResponse(new Problem($response), …)`) that
     * its constructor reads off what the status is read off too ({@see ConstructorEchoes}): the status
     * itself, or its reason phrase. Each is the status-echo marker an array body's member gets inline, keyed
     * by the member's own name; a member echoing anything else says nothing about the status and is left
     * to the schema. Null where no member echoes it.
     *
     * @param  list<string>  $paramNames
     */
    private function constructedEchoes(Node\Expr $body, ClassT $payload, ?ParamAccessor $statusSource, Scope $scope, array $paramNames): ?ArrayShapeT
    {
        if ($statusSource !== null && $body instanceof Node\Expr\Variable && is_string($body->name)) {
            // Built in a local: what its constructor read is the status sent only if nothing since changed it.
            $assigned = ($this->locals($scope))($body->name);
            $body = $assigned !== null && $this->readHolds($scope, $assigned->getEndFilePos(), $statusSource->param, $statusSource->kind !== AccessorKind::Identity) ? $assigned : $body;
        }
        if ($statusSource === null
            || ! $body instanceof Node\Expr\New_
            || ! $body->class instanceof Node\Name
            || $scope->resolveName($body->class) !== $payload->fqcn
        ) {
            return null;
        }

        $args = ConstructorArgs::named($body, $this->constructorParameterNames($payload->fqcn));
        $fields = [];
        foreach ($this->echoes->of($payload->fqcn) as $member => $echo) {
            $arg = $args[$echo['accessor']->param] ?? null;
            $read = $arg === null ? null : AccessorExtractor::rehome($arg, $echo['accessor'], $paramNames);
            if ($read === null || ! $read->equals($statusSource)) {
                continue;
            }

            $fields[] = new ArrayShapeField($member, $echo['text'] ? new StatusTextMarkerT(ScalarT::string(), $echo['fallback']) : new StatusMarkerT);
        }

        return $fields === [] ? null : new ArrayShapeT($fields);
    }

    /**
     * Which members of a body read off a parameter, and which read the status-text table at one
     * ({@see StatusTextRead}) — both off the one array literal and read the same way, so the two meet in
     * {@see RefinedResponse::fromConstructor()} on one grammar. A body built in a local answers only for the
     * reads still true where it goes out ({@see readHolds()}).
     *
     * @param  list<string>  $paramNames
     * @return array{array<string, ParamAccessor>, array<string, array{ParamAccessor, ?LiteralT}>}
     */
    private function bodyProvenance(Node\Expr $body, Scope $scope, array $paramNames): array
    {
        $array = $this->bodyArrayLiteral($body, $scope);
        if ($array === null) {
            return [[], []];
        }

        $locals = $this->locals($scope);
        $provenance = AccessorExtractor::provenanceFromArray($array, $paramNames, $locals);
        $statusTexts = [];
        foreach ($array->items as $item) {
            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }
            $read = StatusTextRead::of(
                $item->value,
                static fn (Node\Name $name): string => $scope->resolveName($name),
                fn (Node\Expr $fallback): ?LiteralT => $this->constLiteralOf($fallback, $scope),
                $this->touchProject(...),
            );
            $accessor = $read === null ? null : AccessorExtractor::fromExpr($read['key'], $paramNames, $locals);
            if ($accessor !== null) {
                $statusTexts[$item->key->value] = [$accessor, $read['fallback']];
            }
        }

        if ($array === $body) {
            return [$provenance, $statusTexts];
        }

        $holds = fn (ParamAccessor $accessor): bool => $this->readHolds($scope, $array->getEndFilePos(), $accessor->param, $accessor->kind !== AccessorKind::Identity);

        return [array_filter($provenance, $holds), array_filter($statusTexts, static fn (array $text): bool => $holds($text[0]))];
    }

    /**
     * The one expression each local of the scope's function was assigned, for an accessor read through it
     * ({@see AccessorExtractor::fromExpr()}); null for a local written in any other way, or more than once,
     * and for one naming a read of a variable that something after it may have changed ({@see readHolds()}).
     *
     * @return Closure(string): ?Node\Expr
     */
    private function locals(Scope $scope): Closure
    {
        $key = FileAnalyzer::scopeKey($scope);
        $assignments = $key === null ? [] : ($this->fileAnalyzer->localAssignments($scope->getFile())[$key] ?? []);

        return function (string $name) use ($assignments, $scope): ?Node\Expr {
            $assigned = ($assignments[$name] ?? null)[0] ?? null;
            $read = $assigned === null ? null : self::variableRead($assigned);

            return $read === null || $this->readHolds($scope, $assigned->getEndFilePos(), ...$read) ? $assigned : null;
        };
    }

    /**
     * Whether a read of `$variable` (a member of it) ending at offset `$after` of the scope's function gives
     * the same value at every later point ({@see FileAnalyzer::readHolds()}).
     */
    private function readHolds(Scope $scope, int $after, string $variable, bool $member): bool
    {
        $key = FileAnalyzer::scopeKey($scope);

        return $key !== null && $this->fileAnalyzer->readHolds($scope->getFile(), $key, $after, $variable, $member, $this->touchProject(...));
    }

    /**
     * `[variable, whether through a member]` where the expression, `??` aside, reads one variable: itself, a
     * property of it, or a method of it.
     *
     * @return array{string, bool}|null
     */
    private static function variableRead(Node\Expr $expr): ?array
    {
        while ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            $expr = $expr->left;
        }

        if ($expr instanceof Node\Expr\Variable) {
            return is_string($expr->name) ? [$expr->name, false] : null;
        }

        $variable = $expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\MethodCall ? $expr->var : null;

        return $variable instanceof Node\Expr\Variable && is_string($variable->name) ? [$variable->name, true] : null;
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
            $key = FileAnalyzer::scopeKey($scope);
            if ($key !== null) {
                return $this->fileAnalyzer->arrayAssignments($scope->getFile())[$key][$expr->name] ?? null;
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
        $status = self::statusArg($statusArg);
        $ctArg = $type->typeArgs[2] ?? null;
        $contentType = $ctArg instanceof LiteralT && is_string($ctArg->value) ? $ctArg->value : null;

        // No member map to read back: this reads a PHPStan type, and the map is written past the last
        // `@template` the stub declares, so only our own descent ever carries one. A payload deciding its
        // own status ({@see RefinedResponse::renderedBy()}) keeps that through a chain unless the chain
        // states a status of its own.
        return new RefinedResponse($payload, $status, null, $contentType, statusOfPayload: $payload !== null && $statusArg instanceof PayloadStatusT);
    }

    /**
     * `response()->json($body, $code)` with a status its type could not fold, read the way the constructor
     * fold reads `new JsonResponse($body, $code)` ({@see foldConstructor()}): a read on an enum case is the
     * status its case maps to, and a status that is one of the enclosing helper's parameters is recorded as
     * the accessor a call site binds, and a body member echoing it as the status marker — so
     * `$this->ok($data, 201)` is a 201 and `$this->ok($data)` the parameter's default, rather than a status
     * nothing read.
     *
     * @param  list<string>  $paramNames
     */
    private function factoryAccessors(RefinedResponse $refined, Node\Expr\MethodCall|Node\Expr\StaticCall $call, Scope $scope, array $paramNames): RefinedResponse
    {
        $arguments = $refined->statusOfPayload ? null : $this->factoryArguments($call, $scope);
        if ($arguments === null) {
            return $refined;
        }

        // Read even where the type folded it, which records where the status was written. The type's own
        // status stands only where this reader has nothing to say against it: a status read off a parameter
        // is the call site's to decide, and a choice of cases that disagree states none.
        $status = $arguments['status'];
        [$codes, $source] = $status === null ? [null, null] : $this->resolveStatus($status, $scope, $paramNames);
        $stated = match (true) {
            $codes !== null => $refined->status ?? $codes,
            $source !== null, $status !== null && $this->caseStatus->disagrees($status, $scope) => null,
            default => $refined->status,
        };

        // A body member read off a parameter binds at the call site whatever the status is.
        $body = $arguments['body'];
        [$provenance, $statusTexts] = $body === null ? [[], []] : $this->bodyProvenance($body, $scope, $paramNames);
        if ($source === null && $provenance === [] && $statusTexts === []) {
            return match (true) {
                $stated === $refined->status => $refined,
                $stated === null => $refined->withStatusSource(null),
                default => $refined->withBoundStatus($stated),
            };
        }

        return RefinedResponse::fromConstructor($refined->payload, $stated, $source, $refined->contentType, $provenance, $statusTexts);
    }

    /** The status argument of a `response()->json()`/`noContent()` call, or null for any other call. */
    private function factoryStatus(Node\Expr\MethodCall|Node\Expr\StaticCall $call, Scope $scope): ?Node\Expr
    {
        return $this->factoryArguments($call, $scope)['status'] ?? null;
    }

    /**
     * The body and status arguments of a `response()->json()`/`noContent()` call, made on the factory or through
     * a facade proxying it ({@see FacadeCall}), or null for any other call.
     *
     * @return array{method: string, body: Node\Expr|null, status: Node\Expr|null, defaultStatus: int|null}|null
     */
    private function factoryArguments(Node\Expr\MethodCall|Node\Expr\StaticCall $call, Scope $scope): ?array
    {
        if ($call instanceof Node\Expr\StaticCall) {
            $call = FacadeCall::onRoot($call, $scope, $this->reflectionProvider);
            if ($call === null) {
                return null;
            }
        }

        $arguments = ResponseFactoryCall::arguments($call);
        if ($arguments === null || ! (new ObjectType(ResponseFactoryCall::CONTRACT))->isSuperTypeOf($scope->getType($call->var))->yes()) {
            return null;
        }

        return $arguments;
    }

    /**
     * A status argument as the refiner carries it: one int literal, or a union of nothing but int literals
     * (`response()->json($b, $ok ? 200 : 503)` types its status as both) — the grammar
     * {@see ScalarFold::ints()} folds, read back off a translated type by the reader the adapter uses too
     * ({@see StatusCodes}). Null for anything else.
     */
    private static function statusArg(?DType $arg): LiteralT|UnionT|null
    {
        return ($arg instanceof LiteralT || $arg instanceof UnionT) && StatusCodes::of($arg) !== null ? $arg : null;
    }

    /**
     * The call-independent shape of a project callee: analyse its return sites (bounded, memoised,
     * cycle-guarded) and fold the first documentable — or delegating — one. Callers gate on
     * {@see ProjectFilter}, so vendor callees never reach here.
     */
    private function refineCallee(Callee $callee, int $depth): ?RefinedResponse
    {
        $key = $callee->key();

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
        $result = $this->declared($this->computeCalleeShape($callee, $depth), $callee);
        $this->budget->close($key, $frame, $result);

        return $result;
    }

    /**
     * Stamp the hop's own `#[ErrorComponent]` over whatever came back from below it — the outermost
     * declaring hop wins ({@see RefinedResponse::withComponent()}). A delegating arm answers with no body,
     * so there is nothing for a name to be about.
     *
     * The file the hop's method is WRITTEN in is touched whether or not it carries a name, and it is not
     * the file the call resolved to: an unoverridden method is the parent's, and a trait-imported one is
     * reported as the using class's own while living in the trait's file. What this hop does not declare
     * is an answer of its own — key only the found case and adding the attribute to a trait method leaves
     * every warm fragment valid, so a warm build publishes the status default where a cold one publishes
     * the declared name.
     */
    private function declared(?RefinedResponse $result, Callee $callee): ?RefinedResponse
    {
        if ($result === null || $result->delegates) {
            return $result;
        }

        // A function's file is touched already, and a function carries no component declaration.
        if ($callee->class === null) {
            return $result;
        }

        $written = $this->declarations->fileFor($callee->class, $callee->method);
        if ($written !== null) {
            $this->touch($written);
        }

        $declaration = $this->declarations->on($callee->class, $callee->method);

        return $declaration === null ? $result : $result->withComponent($declaration);
    }

    /** A file an answer was read out of, recorded where it is the application's own. */
    private function touchProject(string $file): void
    {
        if ($this->appFilter->isProjectFile($file)) {
            $this->touch($file);
        }
    }

    /** Normalise before it reaches the budget, which counts files by their canonical path. */
    private function touch(string $file): void
    {
        $this->budget->touch($this->adapter->normalize($file));
    }

    private function computeCalleeShape(Callee $callee, int $depth): ?RefinedResponse
    {
        $this->touch($callee->file);

        $node = $this->fileAnalyzer->callee($callee);
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
        if ($factory === null || ! $this->appFilter->isProjectFile($factory->file)) {
            return $child; // vendor / unresolvable — a deterministic decline
        }
        if (! $this->budget->withinBudget($this->adapter->normalize($factory->file))) {
            $this->budget->truncate(); // file-budget cutoff — the enclosing shape is truncated

            return $child;
        }

        $this->touch($factory->file);
        $node = $this->fileAnalyzer->callee($factory);
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
     * "Supplied" is what the map means to everything downstream, and an argument that may render as an
     * omission sentinel ({@see OmissionSentinel}) does not supply the key at all on the runs where it does:
     * such a field is marked OPTIONAL rather than asserted, so nothing downstream tells a client the body
     * always carries it. {@see bindPayload()} settles it one hop out when the call site passes a value.
     *
     * Beside them, the members an object built as exactly its class holds one value in ({@see exactValues()}).
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
        $fixed = array_values(array_filter(
            $this->exactValues($new, $fqcn, $scope),
            static fn (ArrayShapeField $field): bool => ! array_key_exists((string) $field->key, $args),
        ));
        if ($args === [] && $fixed === []) {
            return [null, []];
        }

        $fields = [];
        $provenance = [];
        foreach ($args as $name => $value) {
            $sensitive = SensitiveConstant::label($value);
            $literal = $sensitive === null ? $this->constLiteralOf($value, $scope) : null;
            $optional = false;
            if ($literal === null && $sensitive === null) {
                $accessor = AccessorExtractor::fromExpr($value, $paramNames, $this->locals($scope));
                if ($accessor !== null) {
                    $provenance[$name] = $accessor;
                }
                $optional = OmissionSentinel::inType($scope->getType($value));
            }
            $fields[] = new ArrayShapeField(
                $name,
                $literal ?? new UnknownT($sensitive === null ? 'constructor argument not folded' : 'sensitive constant'),
                $optional,
            );
        }

        return [new ArrayShapeT([...$fields, ...$fixed]), $provenance];
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
     * A foldable argument resolves the status outright — an int literal, or an enum case whose accessor
     * folds (`make(ProblemType::Forbidden, …)` → `$problem->status()` → 403); a caller parameter
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

        [$argExpr, $known] = $this->argumentFor($callee, $source->param, $call);
        if ($argExpr === null) {
            // Provably not passed: the parameter took its default, which is the status sent.
            $default = ScalarFold::statusOf($known && $source->kind === AccessorKind::Identity ? $this->parameterDefault($callee, $source->param) : null);

            return $default === null ? $child->withStatusSource(null) : $child->withBoundStatus($default);
        }

        // A forwarded code folds as a status written here does (`$this->respond($b, $ok ? 200 : 503)`); an
        // accessor read off a forwarded case folds on every case the argument may be.
        $codes = match (true) {
            $source->kind !== AccessorKind::Identity => $this->statusIn($argExpr, $scope, $source),
            SensitiveConstant::label($argExpr) === null => $this->statusIn($argExpr, $scope),
            default => null,
        };
        if ($codes !== null) {
            return $child->withBoundStatus($codes);
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
     * An argument that does pass a value settles a member the callee left conditional: the callee's
     * `$param ?? new Optional` only omits the key when the caller had nothing, so a caller that provably
     * had something is a caller whose response carries it ({@see rendersValue()}).
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
            [$argExpr, $known] = $this->argumentFor($callee, $accessor->param, $call);
            if ($argExpr === null) {
                // Only a call that provably passed nothing here says the member isn't in this body. Where a
                // spread may be carrying the value, the member stays and loses its provenance instead.
                $child = $objectMembers && $known
                    ? $child->withoutMember($key)
                    : $child->bindMember($key, null, null);

                continue;
            }

            $literal = $this->foldAccessorArgument($argExpr, $accessor, $scope);
            $rehome = $literal === null ? $this->rehomeAccessor($argExpr, $accessor, $paramNames) : null;

            $child = $child->bindMember($key, $literal, $rehome, $this->rendersValue($argExpr, $accessor, $scope));
        }

        return $child;
    }

    /**
     * Whether the argument passed here renders the key: a value that is neither null (which is what a
     * `?? new Optional` tail waits for) nor a sentinel of its own. Anything less leaves the member
     * conditional.
     *
     * Only an IDENTITY accessor can be answered from out here, because only there is the argument the very
     * value the tail tests. Every other kind reads THROUGH the argument — `$problem->detail() ?? new
     * Optional` waits on the READ, and a caller proving the receiver exists has proved nothing about what
     * the read answers. Nor is a `->value`/`->name` accessor safe on that ground: it is matched by property
     * name alone, so a plain object's nullable `$dto->value` takes the same path as an enum case's.
     */
    private function rendersValue(Node\Expr $argExpr, ParamAccessor $accessor, Scope $scope): bool
    {
        if ($accessor->kind !== AccessorKind::Identity) {
            return false;
        }

        $type = $scope->getType($argExpr);

        return $type->isNull()->no() && ! OmissionSentinel::inType($type);
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
     * A status expression as either the call-independent code(s) it folds to ({@see statusIn()}), the
     * {@see ParamAccessor} it reads from (a parameter, or an accessor on an enum parameter like
     * `$problem->status()`), or neither.
     *
     * @param  list<string>  $paramNames
     * @return array{LiteralT|UnionT|null, ?ParamAccessor}
     */
    private function resolveStatus(Node\Expr $expr, Scope $scope, array $paramNames): array
    {
        $codes = $this->statusIn($expr, $scope);
        if ($codes !== null) {
            return [$codes, null];
        }

        return [null, AccessorExtractor::fromExpr($expr, $paramNames, $this->locals($scope))];
    }

    /**
     * The status an expression states, the one reader of it for every place a response takes one: what a
     * read on an enum case gives ({@see CaseStatus}) — `Reason::Expired->status()`, or with `$read` the
     * accessor a callee applies to the case passed here — or else each constant it can be
     * ({@see ScalarFold::statusOf()}), except a read on cases that disagree ({@see CaseStatus::disagrees()}).
     * Where it was written — the enums the read went through, the class constants it names
     * ({@see ConstantSource}) — is a dependency whether or not it folded.
     */
    private function statusIn(Node\Expr $expr, Scope $scope, ?ParamAccessor $read = null): LiteralT|UnionT|null
    {
        $folded = $this->caseStatus->of($expr, $scope, $read);
        foreach ([...$folded['files'], ...ConstantSource::files($expr, $scope->getClassReflection()?->getName())] as $file) {
            $this->touchProject($file);
        }

        if ($folded['status'] !== null) {
            return new LiteralT($folded['status']);
        }

        return $read === null && ! $this->caseStatus->disagrees($expr, $scope) ? ScalarFold::statusOf($scope->getType($expr)) : null;
    }

    /**
     * Null when the argument doesn't fold to a single scalar — a folded `null` isn't a documentable literal.
     *
     * A value the ANALYSING process could compute and the served response would not is out of scope here
     * on purpose, and the commonest is a translated string: a problem document's `title` written as
     * `__($key)` reads as a constant to anyone holding one locale, and folding it would publish that
     * locale's words as the contract and change the document's bytes with `app.locale` — which
     * determinism forbids. The clock and the environment are the same argument. Such a member stays
     * unread and is illustrated from its schema, which is the honest half of what is known about it.
     * A value the fold CAN read and must still refuse is a different rule, and lives in
     * {@see SensitiveConstant}.
     */
    private function constLiteralOf(Node\Expr $expr, Scope $scope): ?LiteralT
    {
        $folded = ScalarFold::of($scope->getType($expr));

        return $folded !== null && is_scalar($folded[0]) ? new LiteralT($folded[0]) : null;
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

    /**
     * The expression bound to one of the callee's parameters, and whether NOTHING is a real answer for it.
     * The second half is what stops a spread from reading as an omission: `make(...$args)` binds every
     * parameter from a sequence this build cannot see, so a caller told "absent" there would delete a body
     * member the response always carries ({@see ArgumentSlots::knows()}).
     *
     * @return array{?Node\Expr, bool}
     */
    private function argumentFor(Callee $callee, string $paramName, Node\Expr $call): array
    {
        if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall && ! $call instanceof Node\Expr\FuncCall) {
            return [null, true];
        }
        if ($call->isFirstClassCallable()) {
            return [null, true];
        }

        $params = $this->parameterNames($callee);
        $slots = ArgumentSlots::of($call->getArgs(), $params);
        $index = array_search($paramName, $params, true);
        $key = $index === false ? $paramName : $index;

        return [$slots->at($key), $slots->knows($key)];
    }

    /** The default a callee's parameter takes when a call passes nothing for it, or null when it has none. */
    private function parameterDefault(Callee $callee, string $param): ?Type
    {
        foreach ($callee->parameters($this->reflectionProvider) as $parameter) {
            if ($parameter->getName() === $param) {
                return $parameter->getDefaultValue();
            }
        }

        return null;
    }

    /**
     * In declaration order, for positional binding.
     *
     * @return list<string>
     */
    private function parameterNames(Callee $callee): array
    {
        $names = [];
        foreach ($callee->parameters($this->reflectionProvider) as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }
}
