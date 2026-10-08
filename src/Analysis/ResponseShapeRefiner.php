<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
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
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ContentTypeHeader;
use Docuccino\Inference\PhpStan\Support\OmissionSentinel;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Support\ResponseFactoryCall;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

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

    /** Reads the `#[ErrorComponent]` each descended hop declares for the body it answers with. */
    private readonly ComponentDeclarations $declarations;

    /** Peels `->setStatusCode(…)`/`->header(…)` off a response so the shape is read where it was built. */
    private readonly FluentResponseChain $fluentChain;

    /** Reads which members of an object body its constructor takes off its parameters. */
    private readonly ConstructorEchoes $echoes;

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
        int $maxDepth = 4,
        int $fileBudget = 40,
    ) {
        $this->budget = new DescentBudget($maxDepth, $fileBudget);
        $this->declarations = new ComponentDeclarations($reflectionProvider);
        $this->fluentChain = new FluentResponseChain($reflectionProvider, $translator);
        $this->echoes = new ConstructorEchoes($this->touchProject(...));
        $this->enumFolder = new EnumAccessorFolder(
            $this->fileAnalyzer,
            $this->appFilter,
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
                return ResponseArms::allLaid($this->armsOf($chain['receiver'], $scope, $depth, $paramNames) ?? [new RefinedResponse], $chain);
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
     * harvest knows a parameterised type is not the last word. Two shapes qualify: a `new` whose arguments
     * fold to more than the erased generic the stub's `@template` bounds leave behind, and a fluent chain,
     * whose links can restate a status the receiver's own type carries unchanged
     * ({@see FluentResponseChain}).
     */
    public function outranksResolvedType(Node\Expr $expr, Scope $scope): bool
    {
        return $expr instanceof Node\Expr\New_ || $this->fluentChain->peel($expr, $scope) !== null;
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
                return ResponseArms::laid($this->refineExpr($chain['receiver'], $scope, $paramNames, $depth) ?? new RefinedResponse, $chain);
            }
        }

        // 1. `new JsonResponse($body, $status, [headers])` — fold the constructor arguments directly.
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $class = $scope->resolveName($expr->class);
            if (self::isResponseFqcn($class)) {
                return $this->foldConstructor($expr, $class, $scope, $paramNames);
            }
        }

        // 2. Type system already carries the shape (`response()->json([...], 422)`, via our extension).
        $type = $this->translator->translate($scope->getType($expr));
        if ($type instanceof ClassT && self::isResponseFqcn($type->fqcn) && $type->typeArgs !== []) {
            $refined = $this->fromTypeArgs($type);

            return $expr instanceof Node\Expr\MethodCall ? $this->factoryAccessors($refined, $expr, $scope, $paramNames) : $refined;
        }

        // 3. A response built into a local and then returned (`$r = Problem::make(…); …; return $r;`) — the
        // idiomatic shape whenever the protocol headers the exception carries have to be copied onto the
        // response before it goes out. The variable's own type is the bare class, so the shape lives in what
        // was assigned to it ({@see refineLocal()}).
        if ($expr instanceof Node\Expr\Variable
            && is_string($expr->name)
            && $type instanceof ClassT
            && self::isResponseFqcn($type->fqcn)
        ) {
            return $this->refineLocal($expr->name, $scope, $paramNames, $depth);
        }

        // 4. A call into project code whose declared return erased the shape — descend and substitute. A
        // Responsable rendering itself (`$resource->response()`) is answered by whoever wrote its
        // `toResponse()` ({@see rendering()}), and so is `parent::toResponse()` inside the application's
        // own ({@see parentRendering()}). One shape only: an override with several arms is several
        // responses, which only {@see refineArms()} can carry.
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) {
            if ($type instanceof ClassT && self::isResponseFqcn($type->fqcn)) {
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
     * ({@see bindCall()}), and stamped with the `#[ErrorComponent]` the override declares.
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

        if (! $this->budget->withinDepth($depth + 1) || ! $this->budget->withinBudget($this->adapter->normalize($callee->file))) {
            $this->budget->truncate();

            return null;
        }
        $this->touch($callee->file);
        $this->touch($callee->writtenIn());

        $node = $this->fileAnalyzer->method($callee->file, $callee->class, $callee->method);
        if ($node === null) {
            return null;
        }

        $outer = $this->renderingSelf;
        $this->renderingSelf = ['class' => $callee->class, 'receiver' => $render['receiver']];
        $read = [];
        try {
            foreach ($node->getReturnStatements() as $statement) {
                $expr = $statement->getReturnNode()->expr;
                $refined = $expr === null
                    ? null
                    : $this->refineExpr($expr, $this->fileAnalyzer->stableScope($statement->getScope()), $this->parameterNames($callee), $depth + 1);
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
     * variable are not described by either — and never one assigned from another local, which says nothing
     * this call has not already asked and is how a self-assignment would never end.
     *
     * The depth is the caller's: naming a value is not a call hop, and the descent the assignment may
     * itself be counts its own.
     *
     * @param  list<string>  $paramNames
     */
    private function refineLocal(string $name, Scope $scope, array $paramNames, int $depth): ?RefinedResponse
    {
        $key = FileAnalyzer::scopeKey($scope);
        if ($key === null) {
            return null;
        }

        $assignment = $this->fileAnalyzer->localAssignments($scope->getFile())[$key][$name] ?? null;
        if ($assignment === null) {
            return null;
        }

        [$assigned, $assignedScope] = $assignment;
        if ($assigned instanceof Node\Expr\Variable) {
            return null;
        }

        return $this->refineExpr($assigned, $this->fileAnalyzer->stableScope($assignedScope), $paramNames, $depth);
    }

    /**
     * Fold `new JsonResponse($body, $status, [headers])`: payload from arg 0, status from arg 1 (literal,
     * pass-through parameter, or permissive), content type from a `Content-Type` header in arg 2. Slots,
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

        $payload = null;
        [$provenance, $statusTexts] = [[], []];
        $body = $args->at(0);
        if ($body !== null) {
            $payload = $this->payloadOf($scope->getType($body));
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

        $headers = $args->at(2);
        $contentType = $headers === null ? null : ContentTypeHeader::inArray($headers, $scope);

        // A member reading the same accessor as the status echoes the status: the factory marks it, so a
        // call site folding the status folds the member too, and an unfolded one still fills at doc time.
        $refined = RefinedResponse::fromConstructor($payload, $status, $statusSource, $contentType, $provenance, $statusTexts);

        $echoes = $body === null || ! $payload instanceof ClassT ? null : $this->constructedEchoes($body, $payload, $statusSource, $scope, $paramNames);

        return $echoes === null ? $refined : $refined->withPayloadMembers($echoes, []);
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

        return $key !== null && $this->fileAnalyzer->readHolds($scope->getFile(), $key, $after, $variable, $member);
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
     * fold reads `new JsonResponse($body, $code)` ({@see foldConstructor()}): a status that is one of the
     * enclosing helper's parameters is recorded as the accessor a call site binds, and a body member echoing
     * it as the status marker — so `$this->ok($data, 201)` is a 201 and `$this->ok($data)` the parameter's
     * default, rather than a status nothing read.
     *
     * @param  list<string>  $paramNames
     */
    private function factoryAccessors(RefinedResponse $refined, Node\Expr\MethodCall $call, Scope $scope, array $paramNames): RefinedResponse
    {
        $arguments = $refined->status === null && ! $refined->statusOfPayload ? ResponseFactoryCall::arguments($call) : null;
        if ($arguments === null
            || $arguments['status'] === null
            || ! (new ObjectType(ResponseFactoryCall::CONTRACT))->isSuperTypeOf($scope->getType($call->var))->yes()
        ) {
            return $refined;
        }

        $source = $this->resolveStatus($arguments['status'], $scope, $paramNames)[1];
        if ($source === null) {
            return $refined;
        }

        [$provenance, $statusTexts] = $arguments['body'] === null ? [[], []] : $this->bodyProvenance($arguments['body'], $scope, $paramNames);

        return RefinedResponse::fromConstructor($refined->payload, null, $source, $refined->contentType, $provenance, $statusTexts);
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

        $node = $this->fileAnalyzer->method($callee->file, $callee->class, $callee->method);
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
        $node = $this->fileAnalyzer->method($factory->file, $factory->class, $factory->method);
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

        [$argExpr, $known] = $this->argumentFor($callee, $source->param, $call);
        if ($argExpr === null) {
            // Provably not passed: the parameter took its default, which is the status sent.
            $default = ScalarFold::statusOf($known && $source->kind === AccessorKind::Identity ? $this->parameterDefault($callee, $source->param) : null);

            return $default === null ? $child->withStatusSource(null) : $child->withBoundStatus($default);
        }

        // A forwarded code folds to every constant it can be (`$this->respond($b, $ok ? 200 : 503)`); an
        // accessor read off it folds only from one known case.
        $codes = ScalarFold::statusOf($source->kind === AccessorKind::Identity && SensitiveConstant::label($argExpr) === null ? $scope->getType($argExpr) : null);
        if ($codes !== null) {
            return $child->withBoundStatus($codes);
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
     * A status expression as either the call-independent code(s) it folds to — one literal, or each constant
     * of `$ok ? 200 : 503` — the {@see ParamAccessor} it reads from (a parameter, or an accessor on an enum
     * parameter like `$problem->status()`), or neither.
     *
     * @param  list<string>  $paramNames
     * @return array{LiteralT|UnionT|null, ?ParamAccessor}
     */
    private function resolveStatus(Node\Expr $expr, Scope $scope, array $paramNames): array
    {
        $codes = ScalarFold::statusOf($scope->getType($expr));
        if ($codes !== null) {
            return [$codes, null];
        }

        return [null, AccessorExtractor::fromExpr($expr, $paramNames, $this->locals($scope))];
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
        if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
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
        if (! $this->reflectionProvider->hasClass($callee->class)) {
            return null;
        }
        $class = $this->reflectionProvider->getClass($callee->class);
        if (! $class->hasNativeMethod($callee->method)) {
            return null;
        }

        foreach ($class->getNativeMethod($callee->method)->getVariants()[0]->getParameters() as $parameter) {
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
