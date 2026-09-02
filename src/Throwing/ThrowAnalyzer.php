<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\Frame;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Support\Fqcn;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Node\ReturnStatementsNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Type;

/**
 * The 3-layer exception-flow engine (docs/design/inference-embedding.md §6):
 *
 *   1. PHPStan throw points. Drop `!isExplicit()` ones — they're always bare `Throwable`. Do NOT filter on
 *      `canContainAnyThrowable`: nearly every point flags it, signal included.
 *   2. {@see KnownThrowers}, keyed on callee name — enriches explicit stubbed points with a status, and
 *      rescues still-implicit forwarders (static `findOrFail`) at `likely` confidence. Gated on the
 *      RESOLVED callee, so a name-keyed guess never overrules a body we can read ({@see applyRegistry}).
 *   3. Bounded descent (depth 3) into project callees with no `@throws`, cycle-guarded. The vendor-file
 *      gate, not depth, does the real containment.
 *
 * A status comes from {@see KnownThrowers} where a name-keyed entry has one, and otherwise from what an
 * `HttpException` subclass sets on itself ({@see HttpExceptionStatus}) or, for a class that sets none, from
 * the construction THIS throw makes — the arguments of a `throw new X(…)`, or the `new` inside the static
 * factory it names ({@see FactoryStatus}). A throw carrying no construction at all — one inside a closure
 * the callee runs, one written in a trait and declared at the caller, a rethrow — falls back to the status
 * every construction the class writes of itself agrees on. Only when none of them speaks is the answer the
 * 500 that means "not an HTTP error at all" — which is the fallback {@see ThrowSignal} reads to tell an API
 * error from vendor plumbing.
 *
 * Result identity is `(fqcn, httpStatusHint)` — two aborts (403/404) are two responses, so never dedupe by
 * FQCN alone. Vendor-declared 500-class exceptions are demoted to `internal`; dropped bare-`Throwable`
 * noise is discarded silently — how much of it there was says nothing about the API, and nothing the
 * author writes would change it.
 *
 * @internal
 */
final class ThrowAnalyzer
{
    /** @var array<string, true> */
    private array $visitedFiles = [];

    /** @var array<string, true> HttpException subclasses whose status did not fold, by FQCN */
    private array $unreadStatuses = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ProjectFilter $projectFilter,
        private readonly FileAnalyzer $fileAnalyzer,
        private readonly KnownThrowers $knownThrowers,
        private readonly CalleeResolver $calleeResolver,
        private readonly HttpExceptionStatus $httpExceptionStatus,
        private readonly FactoryStatus $factoryStatus,
        // No default: the budget is `EngineConfig::$throwDepth` and nowhere else. A second copy here
        // was dead — the one construction always passes the config's — and it read as the real one, so
        // changing it moved nothing while looking like it had.
        private readonly int $maxDepth,
    ) {}

    /**
     * @return list<ThrownException>
     */
    public function analyze(MethodReturnStatementsNode $node, string $selfLabel): array
    {
        $this->visitedFiles = [];
        $this->unreadStatuses = [];

        $raw = $this->analyzeMethod($node, $selfLabel, 0, [], []);

        return $this->dedupe($raw);
    }

    /**
     * @return list<string>
     */
    public function visitedFiles(): array
    {
        return array_keys($this->visitedFiles);
    }

    /**
     * One notice per PROJECT exception class whose HTTP status this build could not read — not one per
     * throw site, and never for a class the author does not own. They ride the analysis, so a warm build
     * reports what a cold one did. Where it fires, and the measurement that sized its population, are in
     * docs/design/inference-embedding.md §6.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        $classes = array_keys($this->unreadStatuses);
        sort($classes);

        return array_map(
            static fn (string $fqcn): Diagnostic => new Diagnostic(
                severity: Severity::Info,
                code: 'inference.http-exception-status-unread',
                message: sprintf(
                    '%s extends HttpException, but the status it sets could not be read; the error is documented without a status of its own.',
                    $fqcn,
                ),
                help: 'Say the status where the exception is built, as a constant — a literal or a class constant both fold, and so does the constructor default a construction leaves the slot empty for. A status chosen at run time is not one: this build cannot tell which of them the response is. Pin it in the class with `parent::__construct(409, …)` if every instance is that status, and otherwise write it at the `throw`, or in the static factory the `throw` names.',
            ),
            $classes,
        );
    }

    /**
     * @param  list<string>  $visited
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>
     */
    private function analyzeMethod(
        ReturnStatementsNode $methodNode,
        string $selfLabel,
        int $depth,
        array $visited,
        array $priorChain,
    ): array {
        $results = [];

        foreach ($methodNode->getStatementResult()->getThrowPoints() as $throwPoint) {
            $node = $throwPoint->getNode();
            $type = $throwPoint->getType();
            $scope = $this->fileAnalyzer->stableScope($throwPoint->getScope());
            $explicit = $throwPoint->isExplicit();
            $calleeName = $this->calleeResolver->name($node);
            $callee = $this->calleeResolver->resolve($node, $scope);
            $frame = $this->frame($selfLabel, $scope, $node);

            // Layer 3': a closure handed to the callee. Ahead of the layers because they each `continue`,
            // and because the callee's own answer says nothing about what the closure it runs throws.
            foreach ($this->applyClosures($node, $scope, $selfLabel, $depth, $visited, $priorChain, $frame) as $result) {
                $results[] = $result;
            }

            // Layer 2: KnownThrowers registry, keyed on the callee name — for callees we cannot read.
            $registryResult = $this->applyRegistry($calleeName, $callee, $node, $scope, $type, $explicit, $priorChain, $frame);
            if ($registryResult !== null) {
                $results[] = $registryResult;

                continue;
            }

            // Layer 1: explicit concrete type (literal throw, @throws, stub).
            if ($explicit && ! $this->isBareThrowable($type)) {
                foreach ($this->applyExplicit($callee, $node, $scope, $type, $priorChain, $frame) as $result) {
                    $results[] = $result;
                }

                continue;
            }

            // Layer 3: implicit bare Throwable — descend, or drop it as noise.
            if (! $explicit) {
                foreach ($this->applyDescent($callee, $depth, $visited, $priorChain, $frame) ?? [] as $result) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * @param  list<Frame>  $priorChain
     */
    private function applyRegistry(
        ?string $calleeName,
        ?Callee $callee,
        Node $node,
        Scope $scope,
        Type $type,
        bool $explicit,
        array $priorChain,
        Frame $frame,
    ): ?ThrownException {
        if ($calleeName === null) {
            return null;
        }

        $thrower = $this->knownThrowers->forFunction($calleeName);
        $status = null;
        if ($thrower !== null) {
            // A function thrower either folds its status from an argument (`abort($status)`) or carries a
            // fixed one — never assume arg 0 (`abort_if` puts it at arg 1).
            $status = $thrower->foldsStatusFromArgument()
                ? $this->foldStatusArg($node, $scope, $thrower->statusArgIndex)
                : $thrower->fixedStatus;
        } else {
            $thrower = $this->knownThrowers->forMethod($calleeName);
            if ($thrower !== null) {
                $status = $thrower->fixedStatus;
            }
        }

        if ($thrower === null || $this->readsCalleeBody($callee)) {
            return null;
        }

        // Certain when PHPStan corroborated the same concrete type; likely when we rescued a bare-Throwable.
        $corroborated = $explicit && in_array($thrower->exceptionFqcn, $type->getObjectClassNames(), true);

        return new ThrownException(
            $thrower->exceptionFqcn,
            $status,
            [...$priorChain, $frame],
            $corroborated ? ThrowConfidence::Certain : ThrowConfidence::Likely,
            ThrowDisposition::Signal,
        );
    }

    /**
     * Whether this build can read the callee's own body — the invariant that keeps layer 2 honest.
     *
     * The registry is keyed on a BARE METHOD NAME, so it may only ever speak for code we cannot read:
     * a framework method behind vendor, a trait's method (which resolves to the USING class's file and
     * isn't declared there), a magic forward, a stub. Where the callee IS a project method whose body
     * this build analyses, layers 1 and 3 read what it actually throws, and a name-keyed guess must
     * never overrule that: an application's own `validate()` throwing its own exception is that
     * exception, not a 422 `ValidationException`.
     *
     * The predicate is deliberately the same one descent uses ({@see applyDescent}) — a project file
     * whose harvest really holds the method — so nothing that layer 3 would have dropped silently
     * loses the registry's rescue. It is asked only after an entry matched, so a build pays for the
     * file analysis only where a call shares a framework method's name.
     */
    private function readsCalleeBody(?Callee $callee): bool
    {
        return $callee !== null
            && $this->projectFilter->isProjectFile($callee->file)
            && $this->fileAnalyzer->method($callee->file, $callee->class, $callee->method) !== null;
    }

    /**
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>
     */
    private function applyExplicit(
        ?Callee $callee,
        Node $node,
        Scope $scope,
        Type $type,
        array $priorChain,
        Frame $frame,
    ): array {
        // php-parser v5 models `throw` only as an expression.
        $isLiteral = $node instanceof Node\Expr\Throw_;

        // A declared exception documents intent only from project code; a vendor `@throws` is plumbing.
        $calleeIsProject = ! $isLiteral && $callee !== null
            && $this->projectFilter->isProjectFile($callee->file);

        // The `@throws` this point is reading is WRITTEN in the callee — a trait's guard clause, a service
        // method — so that file decides which exception the route publishes and joins the dependency set.
        // Descent records its callee for the same reason; an explicit point never reaches descent.
        if ($calleeIsProject) {
            $this->dependOn([$callee->file, $callee->writtenIn()]);
        }

        $results = [];
        foreach ($this->concreteClasses($type) as $class) {
            $resolution = $this->statusForType($class, $node, $scope);
            $results[] = new ThrownException(
                $class,
                $resolution['status'],
                [...$priorChain, $frame],
                $isLiteral ? ThrowConfidence::Certain : ThrowConfidence::Declared,
                ThrowSignal::disposition($isLiteral, $calleeIsProject, $resolution['fellBack']),
            );
        }

        return $results;
    }

    /**
     * @param  list<string>  $visited
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>|null null when there is nothing to descend into
     */
    private function applyDescent(
        ?Callee $callee,
        int $depth,
        array $visited,
        array $priorChain,
        Frame $frame,
    ): ?array {
        // The vendor-file gate, not depth, does the containment: vendor is a terminal, never descended.
        if ($callee === null
            || ! $this->projectFilter->isProjectFile($callee->file)
            || $depth >= $this->maxDepth
        ) {
            return null;
        }

        $key = $callee->key();
        if (in_array($key, $visited, true)) {
            return []; // cycle guard — treated as descended (no drop)
        }

        // Both files: the harvest comes off the declaring class's, and a trait's body is written in another.
        $this->dependOn([$callee->file, $callee->writtenIn()]);
        $childNode = $this->fileAnalyzer->method($callee->file, $callee->class, $callee->method);
        if ($childNode === null) {
            return [];
        }

        $childLabel = Fqcn::short($callee->class).'::'.$callee->method;

        return $this->analyzeMethod(
            $childNode,
            $childLabel,
            $depth + 1,
            [...$visited, $key],
            [...$priorChain, $frame],
        );
    }

    /**
     * The throws of a closure the analysed body hands to the call at this throw point.
     *
     * PHPStan scopes a closure separately, so a `throw` inside one reaches the enclosing method as the
     * CALL that was given the closure — a bare `Throwable` where the callee declares one and nothing at
     * all where it does not. The closure's own body is where the exception and its status are written, and
     * {@see FileAnalyzer::closures()} already holds it against the same walk.
     *
     * One hop and no further, and no interprocedural analysis: the closure has to be one this body itself
     * writes, either at the argument or one assignment behind it. Depth is descent's own, so a closure
     * counts against the same budget a callee does and a callee reached from inside one is bounded by what
     * the closure spent. There is no vendor gate, because there is no new file to gate: the closure is
     * written in the body the analysis is already reading, and refusing it would drop a real error from a
     * route whose action a package happens to ship.
     *
     * @param  list<string>  $visited
     * @param  list<Frame>  $priorChain
     * @return list<ThrownException>
     */
    private function applyClosures(
        Node $node,
        Scope $scope,
        string $selfLabel,
        int $depth,
        array $visited,
        array $priorChain,
        Frame $frame,
    ): array {
        if ($depth >= $this->maxDepth
            || ! $node instanceof Node\Expr\CallLike
            || $node->isFirstClassCallable()
        ) {
            return [];
        }

        $results = [];
        foreach ($node->getArgs() as $argument) {
            $closure = $this->closureArgument($argument->value, $scope);
            if ($closure === null) {
                continue;
            }

            $this->dependOn([$scope->getFile()]);

            // `$visited` travels through untouched: a closure is not a callee anyone can cycle back into,
            // and the depth it spends is what bounds it. What it must not do is lose the callees the path
            // has already descended into, which is what that list is.
            foreach ($this->analyzeMethod(
                $closure,
                $selfLabel.'::{closure}',
                $depth + 1,
                $visited,
                [...$priorChain, $frame],
            ) as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /** The harvested closure one argument is — written at the call, or held in a local behind it. */
    private function closureArgument(Node\Expr $expr, Scope $scope): ?ClosureReturnStatementsNode
    {
        [$written] = $this->localValue($expr, $scope);

        return $written instanceof Node\Expr\Closure
            ? ($this->fileAnalyzer->closures($scope->getFile())[$written->getStartFilePos()] ?? null)
            : null;
    }

    /**
     * @param  list<ThrownException>  $raw
     * @return list<ThrownException>
     */
    private function dedupe(array $raw): array
    {
        $byIdentity = [];
        foreach ($raw as $throw) {
            $key = $throw->identityKey();
            if (! isset($byIdentity[$key])
                || $throw->confidence->rank() > $byIdentity[$key]->confidence->rank()
            ) {
                $byIdentity[$key] = $throw;
            }
        }

        $list = array_values($byIdentity);
        usort($list, static function (ThrownException $a, ThrownException $b): int {
            return [$a->httpStatusHint ?? PHP_INT_MAX, $a->exceptionFqcn]
                <=> [$b->httpStatusHint ?? PHP_INT_MAX, $b->exceptionFqcn];
        });

        return $list;
    }

    private function frame(string $selfLabel, Scope $scope, Node $node): Frame
    {
        return new Frame($selfLabel, new SourceLocation($scope->getFile(), $node->getStartLine()));
    }

    /**
     * The status one call passes in `$argIndex`, folded in the scope AT the call.
     *
     * `$constructor` is the callee's signature where the caller knows it — a `throw new X(…)`, whose
     * constructor names its parameters and defaults the status slot. The registry path passes none: PHPStan
     * hands a function throw point the NORMALIZED call, so its named arguments already sit in the positions
     * the registry indexes, and `abort()` defaults no status to fall back on.
     *
     * @param  array{names: list<string>, default: int|null}  $constructor
     */
    private function foldStatusArg(
        Node $node,
        Scope $scope,
        ?int $argIndex,
        array $constructor = ['names' => [], 'default' => null],
    ): ?int {
        if ($argIndex === null || ! $node instanceof Node\Expr\CallLike) {
            return null;
        }

        return ConstructionStatus::inSlot(
            $node,
            $argIndex,
            $constructor,
            function (Node\Expr $argument) use ($scope): ?int {
                // A `throw new X(HttpStatus::CONFLICT)` takes its status from another file's declaration,
                // which then decides what this route publishes ({@see ConstantSource}).
                $this->dependOn(ConstantSource::files($argument, $scope->getClassReflection()?->getName()));

                $type = $scope->getType($argument);

                return $type instanceof ConstantIntegerType ? $type->getValue() : null;
            },
        );
    }

    /**
     * @param  list<string>  $files
     */
    private function dependOn(array $files): void
    {
        foreach ($files as $file) {
            $this->visitedFiles[$file] = true;
        }
    }

    /**
     * Concrete (non-`Throwable`/`Exception`) object class names on a type.
     *
     * @return list<string>
     */
    private function concreteClasses(Type $type): array
    {
        return array_values(array_filter(
            $type->getObjectClassNames(),
            static fn (string $name): bool => $name !== 'Throwable' && $name !== 'Exception',
        ));
    }

    private function isBareThrowable(Type $type): bool
    {
        return $this->concreteClasses($type) === [];
    }

    /**
     * The status a thrown type carries, and whether that answer is the FALLBACK rather than something the
     * code states. The two are separate because 500 is a real status a class may pin, and because "an HTTP
     * error whose status did not fold" is a third answer again — null, which is not the same claim as "no
     * HTTP status at all".
     *
     * @return array{status: int|null, fellBack: bool}
     */
    private function statusForType(string $fqcn, Node $node, Scope $scope): array
    {
        // KnownThrowers is the single source: exact FQCN wins, else a subclass inherits its parent's status.
        $exact = $this->knownThrowers->statusForExceptionFqcn($fqcn);
        if ($exact !== null) {
            return ['status' => $exact, 'fellBack' => false];
        }

        if ($this->reflectionProvider->hasClass($fqcn)) {
            $reflection = $this->reflectionProvider->getClass($fqcn);
            foreach ($this->knownThrowers->knownStatuses() as $known => $status) {
                if ($reflection->is($known)) {
                    return ['status' => $status, 'fellBack' => false];
                }
            }
        }

        if ($this->httpExceptionStatus->isHttpException($fqcn)) {
            // `fellBack` states the rule {@see ThrowSignal} is written to, which is "no status anyone could
            // read" — not "the answer was the 500". Being an HttpException subclass is not itself a status:
            // a vendor `@throws` of one this build cannot read says no more about the API than any other
            // vendor plumbing, and calling it read would promote it to a Signal with nothing behind it.
            $status = $this->httpStatus($fqcn, $node, $scope);

            return ['status' => $status, 'fellBack' => $status === null];
        }

        return ['status' => 500, 'fellBack' => true]; // internal / unhandled
    }

    /**
     * What an `HttpException` subclass's status is here: the one the class pins on every instance, else the
     * one THIS throw builds it with — and where the throw carries no construction at all, the one every
     * construction the class writes of itself agrees on ({@see HttpExceptionStatus::agreed()}). Null when
     * none of them reads, which earns the class one diagnostic ({@see diagnostics()}).
     *
     * The order is what keeps the last of those honest. A site that DID present a construction has already
     * said what this response is, and a `throw new X($chosenAtRunTime)` that would not fold has said the
     * class's agreement is not it — so the class answers only where nothing at the site could.
     */
    private function httpStatus(string $fqcn, Node $node, Scope $scope): ?int
    {
        // The class's own file now decides what this route publishes, so it joins the dependency set.
        $this->dependOn($this->httpExceptionStatus->filesFor($fqcn));

        $status = $this->httpExceptionStatus->pinned($fqcn);
        if ($status === null) {
            $site = $this->atThrowSite($fqcn, $node, $scope);
            if ($site['spoke']) {
                $status = $site['status'];
            } else {
                $agreement = $this->httpExceptionStatus->agreed($fqcn);
                $status = $agreement['status'];
                $this->dependOn($agreement['files']);
            }
        }

        // Only where the author can act. A vendor exception's status is unreadable for a reason no one
        // reading the notice owns, and the remedy it names is an edit to `vendor/` — the non-actionable
        // firing that trains people to ignore the channel and takes the useful notices with it.
        if ($status === null && $this->declaredInProject($fqcn)) {
            $this->unreadStatuses[$fqcn] = true;
        }

        return $status;
    }

    /** Whether the exception class itself is the application's, which is who a diagnostic can address. */
    private function declaredInProject(string $fqcn): bool
    {
        return $this->reflectionProvider->hasClass($fqcn)
            && $this->projectFilter->isProjectFile($this->reflectionProvider->getClass($fqcn)->getFileName());
    }

    /**
     * The status a `throw` states for a class that pins none: the argument it writes into the slot the
     * class forwards, or — where it names a static factory instead — the one that factory builds with.
     * Read off the construction the throw names, which is the one written at it or one assignment behind
     * it ({@see localValue()}).
     *
     * `spoke` says whether the site PRESENTED a construction at all, which is a different fact from what
     * that construction folded to. A throw point that merely declares the exception, a rethrow of one
     * this body did not build, a throw built somewhere this hop is not entitled to read — none of them say
     * anything about how the exception was constructed, and only there may the class answer for itself. A
     * construction that presented itself and would not fold has spoken: it says the response is whatever
     * was chosen at run time, which the class's own agreement is no evidence for.
     *
     * @return array{status: int|null, spoke: bool}
     */
    private function atThrowSite(string $fqcn, Node $node, Scope $scope): array
    {
        if (! $node instanceof Node\Expr\Throw_) {
            return ['status' => null, 'spoke' => false];
        }

        [$thrown, $scope] = $this->localValue($node->expr, $scope);

        $slot = $this->httpExceptionStatus->statusParameter($fqcn);
        $construction = $this->construction($thrown, $fqcn, $scope);
        if ($construction !== null) {
            return [
                'status' => $slot === null ? null : $this->foldStatusArg(
                    $construction,
                    $scope,
                    $slot,
                    $this->httpExceptionStatus->constructorSlot($fqcn, $slot),
                ),
                'spoke' => true,
            ];
        }

        $factory = $this->factoryName($thrown, $fqcn, $scope);
        if ($factory === null) {
            return ['status' => null, 'spoke' => false];
        }

        $read = $this->factoryStatus->forFactory($fqcn, $factory);
        // The factory's file decides what this route publishes too, so it joins the dependency set.
        $this->dependOn($read['files']);

        return ['status' => $read['status'], 'spoke' => true];
    }

    /**
     * What an expression really names, with the scope that value is written in: the expression itself, or —
     * where it is a local — the one this body assigned to it exactly once. `$e = new X(451); … throw $e;`
     * builds its status one statement behind the throw, and a reader that only matched at the throw called
     * that site silent and let the class's own agreement answer over the top of a construction the code
     * really made; `$reject = function () { … }; $db->transaction($reject);` hands a body over one
     * assignment back the same way.
     *
     * A local written twice, bound by a `catch`, or taken from a parameter answers null from
     * {@see FileAnalyzer::localAssignments()}, and the variable stands — which is the rethrow that says
     * nothing about how the exception was built.
     *
     * The scope travels with the expression because a fold happens where the value is WRITTEN, for the
     * reason {@see FileAnalyzer::scopeAtCall()} states.
     *
     * @return array{Node\Expr, Scope}
     */
    private function localValue(Node\Expr $expr, Scope $scope): array
    {
        if (! $expr instanceof Node\Expr\Variable || ! is_string($expr->name)) {
            return [$expr, $scope];
        }

        $key = FileAnalyzer::scopeKey($scope);
        $assigned = $key === null
            ? null
            : ($this->fileAnalyzer->localAssignments($scope->getFile())[$key][$expr->name] ?? null);

        return $assigned === null ? [$expr, $scope] : $assigned;
    }

    /** The `new X(...)` a thrown expression is, where X is the exception the status is wanted for. */
    private function construction(Node\Expr $expr, string $fqcn, Scope $scope): ?Node\Expr\New_
    {
        if (! $expr instanceof Node\Expr\New_ || ! $expr->class instanceof Node\Name) {
            return null;
        }

        return $scope->resolveName($expr->class) === $fqcn ? $expr : null;
    }

    /**
     * The static factory a thrown expression names ON the exception's own class — `throw X::conflicting()`.
     * A factory called on anything else builds through code this hop is not entitled to read as X's.
     */
    private function factoryName(Node\Expr $expr, string $fqcn, Scope $scope): ?string
    {
        if (! $expr instanceof Node\Expr\StaticCall
            || ! $expr->class instanceof Node\Name
            || ! $expr->name instanceof Node\Identifier
            || $scope->resolveName($expr->class) !== $fqcn
        ) {
            return null;
        }

        return $expr->name->toString();
    }
}
