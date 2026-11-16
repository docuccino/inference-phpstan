<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Inference\DeclarationLine;
use Docuccino\Core\Inference\LocalWrites;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Trace\Callee;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\FunctionReturnStatementsNode;
use PHPStan\Node\InArrowFunctionNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\ObjectType;
use Throwable;

/**
 * Parses a file once and harvests everything the engine reads out of it: the virtual
 * `MethodReturnStatementsNode`s — the node that pairs every `return` with its flow-refined scope and carries
 * the method's throw points — and their `FunctionReturnStatementsNode` twins, plus the closures and the local
 * assignments. Memoised per file so descent reuses one rich parse; the adapter's priming is what keeps the
 * bodies from being stripped.
 *
 * @phpstan-type FileHarvest array{
 *     methods: array<string, MethodReturnStatementsNode>,
 *     functions: array<string, FunctionReturnStatementsNode>,
 *     closures: array<int, ClosureReturnStatementsNode>,
 *     arrows: array<int, array{Node\Expr\ArrowFunction, Scope}>,
 *     arrays: array<string, array<string, Node\Expr\Array_>>,
 *     locals: array<string, array<string, array{Node\Expr, Scope}|null>>,
 *     calls: array<string, Scope>,
 *     branches: array<string, array{Scope, Scope}>,
 *     mentions: array<string, array<string, list<int>>>,
 *     members: array<int, true>,
 *     written: array<int, true>,
 *     handed: array<int, array{Node\Expr\CallLike, ?Node\Arg, Scope}>,
 * }
 *
 * @internal
 */
final class FileAnalyzer
{
    /** @var array<string, FileHarvest> */
    private array $cache = [];

    /** @var array<string, array<int, list<int>>> by normalised file, {@see closureLines()} */
    private array $declared = [];

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly FileWalks $walks,
        private readonly StateKeepingCalls $keeping = new StateKeepingCalls,
    ) {}

    /**
     * Every node this class hands out is consumed after its walk finished, so the scopes hanging off them
     * must be stabilised before they are queried — see {@see RuntimeAdapter::stableScope()}. That is the
     * scope INSIDE a harvested node (a return statement's), which no walk hands to a callback; the callback
     * scopes {@see FileWalks} deals in arrive stabilised already, and this is a no-op on them.
     */
    public function stableScope(Scope $scope): Scope
    {
        return $this->adapter->stableScope($scope);
    }

    /**
     * Every method body in the file by `Class::method` — the class half because one file can declare the
     * same method name twice (a renderer beside the inline one it falls back to), and answering either
     * from the other publishes a response that code never sends.
     *
     * @return array<string, MethodReturnStatementsNode>
     */
    public function analyze(string $file): array
    {
        return $this->harvest($file)['methods'];
    }

    /**
     * One method's body, asked for by the class that declares it. A caller with no class to name asks by
     * method name alone, which is answered only while the file declares that name once — where it declares
     * it twice, no answer is the honest one.
     */
    public function method(string $file, ?string $class, string $method): ?MethodReturnStatementsNode
    {
        $bodies = $this->analyze($file);

        if ($class !== null && isset($bodies[$class.'::'.$method])) {
            return $bodies[$class.'::'.$method];
        }

        $found = [];
        foreach ($bodies as $key => $body) {
            if ($body->getMethodName() === $method) {
                $found[$key] = $body;
            }
        }

        return count($found) === 1 ? reset($found) : null;
    }

    /** The body a resolved call runs: the method's, or the function's, which the file keys by its name. */
    public function callee(Callee $callee): MethodReturnStatementsNode|FunctionReturnStatementsNode|null
    {
        return $callee->class === null
            ? $this->harvest($callee->file)['functions'][strtolower($callee->method)] ?? null
            : $this->method($callee->file, $callee->class, $callee->method);
    }

    /**
     * The file's closures by START OFFSET, which is what a caller holding the `Closure` node asks with.
     * Keyed by offset rather than by line for the reason {@see scopeAtCall()} is: an offset is unique per
     * node and survives a re-parse, and two closures written on one line are two bodies a line-keyed map
     * answers the last of for both. {@see callableAtLine()} is the ask a caller with only a line makes.
     *
     * @return array<int, ClosureReturnStatementsNode>
     */
    public function closures(string $file): array
    {
        return $this->harvest($file)['closures'];
    }

    /**
     * The file's arrow functions by start offset, each with the scope inside it — {@see closures()} for the
     * closures PHP spells with `fn`.
     *
     * @return array<int, array{Node\Expr\ArrowFunction, Scope}>
     */
    public function arrows(string $file): array
    {
        return $this->harvest($file)['arrows'];
    }

    /**
     * The closure or arrow function declared at `$line`, for the caller that has no offset to ask with: an
     * exception-handler callback, which `ReflectionFunction` gives us as file+line and nothing else. Null where the line declares none, or more than one — see {@see closureAt()}.
     */
    public function callableAtLine(string $file, int $line): ?CallableBody
    {
        $offset = $this->closureAt($file, $line);
        if ($offset === null) {
            return null;
        }

        $harvest = $this->harvest($file);
        if (isset($harvest['closures'][$offset])) {
            return CallableBody::ofClosure($harvest['closures'][$offset]);
        }
        if (isset($harvest['arrows'][$offset])) {
            [$arrow, $scope] = $harvest['arrows'][$offset];

            return CallableBody::ofArrow($arrow, $scope);
        }

        return null;
    }

    /**
     * The start offset of the one closure or arrow function whose keyword `ReflectionFunction` places on
     * `$line` ({@see DeclarationLine}), or null where the file declares none there or several — reflection
     * cannot tell two apart, and answering either would publish one closure's response as the other's.
     */
    public function closureAt(string $file, int $line): ?int
    {
        $offsets = $this->closuresOn($file, $line);

        return count($offsets) === 1 ? $offsets[0] : null;
    }

    /** How many closures and arrow functions {@see closureAt()} finds on `$line` — it answers for one only. */
    public function closuresDeclaredOn(string $file, int $line): int
    {
        return count($this->closuresOn($file, $line));
    }

    /**
     * @return list<int>
     */
    private function closuresOn(string $file, int $line): array
    {
        $normalised = $this->adapter->normalize($file);
        $lines = $this->declared[$normalised] ??= self::closureLines($file);

        return $lines[$line] ?? [];
    }

    /**
     * @return array<int, list<int>> keyword line → the start offset of every closure declared there
     */
    private static function closureLines(string $file): array
    {
        try {
            $source = is_file($file) ? file_get_contents($file) : false;
            $statements = $source === false ? null : (new ParserFactory)->createForHostVersion()->parse($source);
        } catch (Throwable) {
            return [];
        }
        if ($source === false || $statements === null) {
            return [];
        }

        $lines = [];
        foreach ((new NodeFinder)->find($statements, static fn (Node $node): bool => $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) as $closure) {
            if (! $closure instanceof Node\FunctionLike) {
                continue;
            }
            $line = DeclarationLine::of($closure, $source);
            if ($line !== null) {
                $lines[$line][] = $closure->getStartFilePos();
            }
        }

        return $lines;
    }

    /**
     * The scope PHPStan resolved AT one construction, static or method call, or null where the file holds no such
     * call at that position. Keyed by span rather than by node identity: the same file parses to
     * fresh nodes whenever a recorded walk has been discarded, and a span survives that where an object
     * handle does not.
     *
     * This is the scope a reader folding one ARGUMENT of that call must use. The method's end scope answers
     * a different question — what a variable holds once the body has finished — and a constructor that
     * reassigns its status parameter after forwarding it makes the two disagree, with the end scope naming
     * a value the callee never received.
     */
    public function scopeAtCall(string $file, Node\Expr\New_|Node\Expr\StaticCall|Node\Expr\MethodCall $call): ?Scope
    {
        return $call->getStartFilePos() < 0 ? null : ($this->harvest($file)['calls'][self::spanKey($call)] ?? null);
    }

    /**
     * The scopes the walk evaluated a ternary's `if` and `else` branches in, or null where it did not reach
     * both. The walk's own rather than the return's scope filtered afterwards: a narrowing applied once the
     * walk is over has no analysis left to read the condition's type from, and may drop what a branch
     * proves about an untracked call (`$request->is('api/*')`) rather than answer it.
     *
     * @return array{Scope, Scope}|null
     */
    public function branchScopes(string $file, Node\Expr\Ternary $ternary): ?array
    {
        return $this->harvest($file)['branches'][self::spanKey($ternary)] ?? null;
    }

    /**
     * The file's `$var = [ ... ]` assignments by scope ({@see scopeKey()}) then variable name, first
     * assignment winning. Lets the refiner recover provenance for a body built up in a local
     * (`$body = [...]` then conditional `$body[...] = …`) rather than written inline. The appends are
     * ignored — the payload shape still comes from PHPStan's inferred type of the variable at the return.
     *
     * @return array<string, array<string, Node\Expr\Array_>>
     */
    public function arrayAssignments(string $file): array
    {
        return $this->harvest($file)['arrays'];
    }

    /**
     * Every local's assignment by scope ({@see scopeKey()}) then variable name, as `[what was assigned, the
     * scope it was assigned in]` — and NULL for a local written more than once, written in any of the ways
     * that leave no expression to speak for it ({@see LocalWrites}, plus a call writing it through a
     * by-reference parameter), or sitting in a scope whose writes could not be read at all. Lets the refiner
     * follow a response built into a local and then returned back to the expression that built it, whose
     * shape the variable's own bare type has already thrown away.
     *
     * The scope is the one at the ASSIGNMENT, not at the return: an expression read in the wrong scope
     * binds whatever the arguments happen to hold later, which is how a shape stops being true.
     *
     * @return array<string, array<string, array{Node\Expr, Scope}|null>>
     */
    public function localAssignments(string $file): array
    {
        return $this->harvest($file)['locals'];
    }

    /**
     * Whether a read of `$variable` — a member of it, or the variable itself where `$member` is false —
     * ending at offset `$after` still gives the same value anywhere later in the scope's body ({@see scopeKey()}):
     * nothing after it writes the variable, and, for a member, nothing names the variable except to read a
     * property of it, call one of its methods with no arguments, test its class, or make a call that keeps the
     * object as it was ({@see StateKeepingCalls}). Any other call (`->setStatusCode(503)`), or the object handed
     * where it cannot be followed, may have changed what the member gives, so a value named before one is not
     * what the read gives at the return.
     *
     * @param  ?Closure(string): void  $touch  records each file a call's answer was read out of
     */
    public function readHolds(string $file, string $key, int $after, string $variable, bool $member, ?Closure $touch = null): bool
    {
        $harvest = $this->harvest($file);
        foreach ($harvest['mentions'][$key][$variable] ?? [] as $at) {
            if ($at <= $after) {
                continue;
            }
            if (isset($harvest['written'][$at])
                || ($member && ! isset($harvest['members'][$at]) && ! $this->kept($harvest['handed'][$at] ?? null, $touch))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the call made on the variable mentioned at offset `$at`, or handed it there, keeps the object it
     * holds ({@see StateKeepingCalls}). `$madeOn` is the call made on it, which the harvest records only where
     * it takes arguments.
     *
     * @param  ?Closure(string): void  $touch
     */
    public function keepsAt(string $file, int $at, ?Node\Expr\MethodCall $madeOn, ?Closure $touch = null): bool
    {
        $call = $this->harvest($file)['handed'][$at] ?? null;
        if ($call === null && $madeOn !== null) {
            $scope = $this->scopeAtCall($file, $madeOn);
            $call = $scope === null ? null : [$madeOn, null, $scope];
        }

        return $this->kept($call, $touch);
    }

    /**
     * The statements of the innermost method, function or closure in the file whose body holds offset `$at`,
     * or null where none does.
     *
     * @return list<Node\Stmt>|null
     */
    public function bodyAround(string $file, int $at): ?array
    {
        $harvest = $this->harvest($file);
        $bodies = [
            ...array_map(static fn (MethodReturnStatementsNode $node): array => $node->getStatements(), array_values($harvest['methods'])),
            ...array_map(static fn (FunctionReturnStatementsNode $node): array => $node->getStatements(), array_values($harvest['functions'])),
            ...array_map(static fn (ClosureReturnStatementsNode $node): array => $node->getClosureExpr()->stmts, array_values($harvest['closures'])),
        ];

        $found = null;
        $from = -1;
        foreach ($bodies as $statements) {
            $statements = array_values($statements);
            $first = $statements[0] ?? null;
            $last = $statements[count($statements) - 1] ?? null;
            if ($first !== null && $last !== null && $first->getStartFilePos() <= $at && $last->getEndFilePos() >= $at && $first->getStartFilePos() > $from) {
                [$found, $from] = [$statements, $first->getStartFilePos()];
            }
        }

        return $found;
    }

    /**
     * Whether a call keeps the object it is made on or handed, recording every file the answer was read out of.
     *
     * @param  array{Node\Expr\CallLike, ?Node\Arg, Scope}|null  $call
     * @param  ?Closure(string): void  $touch
     */
    private function kept(?array $call, ?Closure $touch): bool
    {
        if ($call === null) {
            return false;
        }

        try {
            [$kept, $read] = $this->keeping->atCall(...$call);
        } catch (Throwable) {
            return false; // a type PHPStan could not resolve: nothing says the call kept it
        }

        if ($touch !== null) {
            foreach ($read as $file) {
                $touch($file);
            }
        }

        return $kept;
    }

    /**
     * Which body a harvested node belongs to: `Class::method`, or a bare function name outside a class.
     * The class half is what keeps two same-named methods in one file — a `render()` per renderer — from
     * sharing one variable's assignment, which would publish one class's response body for the other's
     * return. Null outside any function, where there are no locals to harvest.
     */
    public static function scopeKey(Scope $scope): ?string
    {
        $function = $scope->getFunctionName();
        if ($function === null) {
            return null;
        }

        $class = $scope->getClassReflection()?->getName();

        return $class === null ? $function : $class.'::'.$function;
    }

    /**
     * Every harvest off ONE walk of the file — the method bodies, the closures, the array-literal
     * initialisers and every local's single assignment. Memoised together because they read the same nodes:
     * a second harvest would have to walk the file again to collect what this one already saw. The walk
     * itself comes from {@see FileWalks}, so it is also the walk the trace reuses.
     *
     * @return FileHarvest
     */
    private function harvest(string $file): array
    {
        $normalised = $this->adapter->normalize($file);
        if (isset($this->cache[$normalised])) {
            return $this->cache[$normalised];
        }

        /** @var array<string, MethodReturnStatementsNode> $methods `Class::method` → its returns */
        $methods = [];
        /** @var array<string, FunctionReturnStatementsNode> $functions by lower-cased name, as PHP matches one */
        $functions = [];
        /** @var array<int, ClosureReturnStatementsNode> $closures by start offset */
        $closures = [];
        /** @var array<int, array{Node\Expr\ArrowFunction, Scope}> $arrows by start offset, with the scope inside */
        $arrows = [];
        /** @var array<string, array<string, Node\Expr\Array_>> $arrays */
        $arrays = [];
        /** @var array<string, array<string, array{Node\Expr, Scope}|null>> $locals */
        $locals = [];
        /** @var array<string, Scope> $calls the scope at each call a status read folds an argument of, by span */
        $calls = [];
        /** @var array<string, true> $opaque scopes where a write named no single local */
        $opaque = [];
        /** @var array<int, array{Node\Expr, Scope}> $evaluated every expression's scope, by object id, until the walk ends */
        $evaluated = [];
        /** @var list<Node\Expr\Ternary> $ternaries */
        $ternaries = [];
        /** @var array<string, array<string, list<int>>> $mentions each variable's offsets, by scope, {@see readHolds()} */
        $mentions = [];
        /** @var array<int, true> $members offsets of a variable a member is read off, with no arguments, or whose class is tested */
        $members = [];
        /** @var array<int, true> $written offsets of a variable written, itself or through what it holds */
        $written = [];
        /** @var array<int, array{Node\Expr\CallLike, ?Node\Arg, Scope}> $handed offsets of a variable a call is made on with arguments, or handed to */
        $handed = [];

        $this->walks->walk($file, function (Node $node, Scope $scope) use (&$methods, &$functions, &$closures, &$arrows, &$arrays, &$locals, &$calls, &$opaque, &$evaluated, &$ternaries, &$mentions, &$members, &$written, &$handed): void {
            // Watching for these virtual nodes is the sanctioned way to pair returns with refined scope.
            // Collected first and outside the guard below, so that a reader wanting only a method body — the
            // throw analyzer, the tracer descending into a callee — never pays for the write half's failures.
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof MethodReturnStatementsNode) {
                $methods[$node->getClassReflection()->getName().'::'.$node->getMethodName()] = $node;
            }

            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof FunctionReturnStatementsNode) {
                $functions[strtolower($node->getFunctionReflection()->getName())] = $node;
            }

            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof ClosureReturnStatementsNode
                && $node->getClosureExpr()->getStartFilePos() >= 0
            ) {
                $closures[$node->getClosureExpr()->getStartFilePos()] = $node;
            }

            // An arrow function has no return-statements node of its own: its body is one expression, typed
            // in the scope PHPStan hands this callback for the node that stands for its inside.
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof InArrowFunctionNode && $node->getOriginalNode()->getStartFilePos() >= 0) {
                $arrows[$node->getOriginalNode()->getStartFilePos()] = [$node->getOriginalNode(), $scope];
            }

            // The call forms whose arguments a status read folds ({@see scopeAtCall()}), paired with the scope
            // they are evaluated in. Collected here rather than on a walk of their own, so the pairing is the
            // same walk's and costs nothing beyond the entry. Keyed by span, since `Foo::make()->send()` starts
            // two of them at one offset.
            if (($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall)
                && $node->getStartFilePos() >= 0
            ) {
                $calls[self::spanKey($node)] = $scope;
            }

            // A ternary's branches are paired with their scopes once the walk is done, since which of a node
            // and its children the callback meets first is not something the walk promises.
            if ($node instanceof Node\Expr) {
                $evaluated[spl_object_id($node)] = [$node, $scope];
                if ($node instanceof Node\Expr\Ternary && $node->if !== null) {
                    $ternaries[] = $node;
                }
            }

            $key = null;

            try {
                $key = self::scopeKey($scope);
                if ($key === null) {
                    return; // outside any function, where there are no locals to harvest
                }

                if ($node instanceof Node\Expr\Variable && is_string($node->name) && $node->getStartFilePos() >= 0) {
                    $mentions[$key][$node->name][] = $node->getStartFilePos();
                }
                $member = self::memberRead($node);
                if ($member !== null) {
                    $members[$member] = true;
                }
                foreach (self::writtenVariables($node) as $at) {
                    $written[$at] = true;
                }
                if ($node instanceof Node\Expr\CallLike) {
                    foreach (self::handedVariables($node) as $at => $argument) {
                        $handed[$at] = [$node, $argument, $scope];
                    }
                }

                $assignment = LocalWrites::assignment($node);
                if ($assignment !== null) {
                    [$name, $expr] = $assignment;
                    // A second write retires the first: the variable at the return is whichever branch ran, and
                    // picking one of them would publish a body the other branch never sends.
                    $locals[$key][$name] = array_key_exists($name, $locals[$key] ?? []) ? null : [$expr, $scope];

                    if ($expr instanceof Node\Expr\Array_) {
                        // First assignment wins — the initialiser carries the provenance.
                        $arrays[$key][$name] ??= $expr;
                    }
                }

                // Every other way the language writes a local — see LocalWrites for the list — plus the one no
                // expression shows, a callee assigning through a by-reference parameter.
                foreach ([...LocalWrites::retires($node), ...$this->byReferenceWrites($node, $scope)] as $name) {
                    $locals[$key][$name] = null;
                }

                if (LocalWrites::retiresEveryLocal($node)) {
                    $opaque[$key] = true;
                }
            } catch (Throwable) {
                // Resolving a callee is the one thing here that can throw out of PHPStan, and a scope whose
                // writes could not be read is the scope an unreadable write already retires — vague but true,
                // and confined to the answers it is about. Letting it out instead would cost every return and
                // throw point in the file a shape this same walk had already recovered.
                if ($key !== null) {
                    $opaque[$key] = true;
                }
            }
        });

        // A scope where one write landed on a local nothing names has no trustworthy local left. The array
        // initialisers are provenance only — the payload shape still comes from PHPStan — so they stand.
        foreach (array_keys($opaque) as $key) {
            $locals[$key] = array_map(static fn (): null => null, $locals[$key] ?? []);
        }

        $branches = [];
        foreach ($ternaries as $ternary) {
            $if = $ternary->if === null ? null : ($evaluated[spl_object_id($ternary->if)] ?? null);
            $else = $evaluated[spl_object_id($ternary->else)] ?? null;
            if ($if !== null && $else !== null && $if[0] === $ternary->if && $else[0] === $ternary->else && $ternary->getStartFilePos() >= 0) {
                $branches[self::spanKey($ternary)] = [$if[1], $else[1]];
            }
        }

        return $this->cache[$normalised] = [
            'methods' => $methods,
            'functions' => $functions,
            'closures' => $closures,
            'arrows' => $arrows,
            'arrays' => $arrays,
            'locals' => $locals,
            'calls' => $calls,
            'branches' => $branches,
            'mentions' => $mentions,
            'members' => $members,
            'written' => $written,
            'handed' => $handed,
        ];
    }

    /**
     * The variable's offset, where the node reads a member straight off a variable — a property, or a method
     * called with no arguments — or tests its class.
     */
    private static function memberRead(Node $node): ?int
    {
        if ($node instanceof Node\Expr\Instanceof_) {
            return $node->expr instanceof Node\Expr\Variable ? $node->expr->getStartFilePos() : null;
        }

        $called = $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall;
        if (! $called && ! $node instanceof Node\Expr\PropertyFetch && ! $node instanceof Node\Expr\NullsafePropertyFetch) {
            return null;
        }

        return $node->var instanceof Node\Expr\Variable && (! $called || (! $node->isFirstClassCallable() && $node->getArgs() === []))
            ? $node->var->getStartFilePos()
            : null;
    }

    /**
     * Where the node is a call: the offset of a variable it is made on with arguments (to null), and of each
     * variable it is handed as an argument (to that argument), for {@see readHolds()} to ask whether it kept them.
     *
     * @return array<int, ?Node\Arg>
     */
    private static function handedVariables(Node\Expr\CallLike $node): array
    {
        if ($node->isFirstClassCallable()) {
            return [];
        }

        $handed = [];
        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && $node->var instanceof Node\Expr\Variable
            && $node->getArgs() !== []
        ) {
            $handed[$node->var->getStartFilePos()] = null;
        }
        foreach ($node->getArgs() as $argument) {
            if ($argument->value instanceof Node\Expr\Variable && ! $argument->unpack) {
                $handed[$argument->value->getStartFilePos()] = $argument;
            }
        }

        return $handed;
    }

    /**
     * The offset of each variable the node writes, itself or through a property or offset of what it holds
     * (`$response->headers = …`, `$body['status']++`, `unset($response->content)`).
     *
     * @return list<int>
     */
    private static function writtenVariables(Node $node): array
    {
        $targets = match (true) {
            $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
            $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PostInc,
            $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostDec => [$node->var],
            $node instanceof Node\Stmt\Unset_ => $node->vars,
            default => [],
        };

        $offsets = [];
        foreach ($targets as $target) {
            while ($target instanceof Node\Expr\PropertyFetch || $target instanceof Node\Expr\NullsafePropertyFetch || $target instanceof Node\Expr\ArrayDimFetch) {
                $target = $target->var;
            }
            if ($target instanceof Node\Expr\Variable) {
                $offsets[] = $target->getStartFilePos();
            }
        }

        return $offsets;
    }

    /** Start and end offset: `($a ? $b : $c) ? $d : $e` nests two ternaries that start at one offset. */
    private static function spanKey(Node $node): string
    {
        return $node->getStartFilePos().':'.$node->getEndFilePos();
    }

    /**
     * The locals a call writes THROUGH: an argument bound to a by-reference parameter, which no expression
     * at the call site shows. A callee that cannot be resolved cannot write one either — `__call` is handed
     * a copy of its arguments — so declining there is sound rather than merely quiet.
     *
     * @return list<string>
     */
    private function byReferenceWrites(Node $node, Scope $scope): array
    {
        if (! $node instanceof Node\Expr\FuncCall
            && ! $node instanceof Node\Expr\MethodCall
            && ! $node instanceof Node\Expr\StaticCall
        ) {
            return [];
        }
        if ($node->isFirstClassCallable()) {
            return []; // a callable, not a call
        }
        if (! self::hasVariableArgument($node)) {
            // Resolving the callee is the expensive half — and the half PHPStan can throw out of — so a call
            // that passes nothing a reference could bind to skips it. Most calls in a file are that call.
            return [];
        }

        $parameters = $this->parametersOf($node, $scope);
        if ($parameters === null) {
            return [];
        }

        $written = [];
        foreach ($node->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                break; // a spread breaks positional binding; nothing past it can be bound
            }

            $parameter = $arg->name instanceof Node\Identifier
                ? self::parameterNamed($parameters, $arg->name->toString())
                : ($parameters[$index] ?? self::variadicTail($parameters));

            if ($arg->value instanceof Node\Expr\Variable
                && is_string($arg->value->name)
                && $parameter?->passedByReference()->createsNewVariable() === true
            ) {
                $written[] = $arg->value->name;
            }
        }

        return $written;
    }

    /**
     * Whether any argument is a plain `$var`, the only form {@see byReferenceWrites()} ever reports. Reads
     * the same grammar as that loop, spread included — recognising fewer shapes would skip a call whose write
     * the loop would have found.
     */
    private static function hasVariableArgument(Node\Expr\FuncCall|Node\Expr\MethodCall|Node\Expr\StaticCall $node): bool
    {
        foreach ($node->getArgs() as $arg) {
            if ($arg->unpack) {
                return false; // as in the loop: nothing past a spread binds positionally
            }

            if ($arg->value instanceof Node\Expr\Variable && is_string($arg->value->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The callee's parameters, or null when nothing resolves the call — a dynamic name, an unknown
     * receiver, a magic method.
     *
     * @return list<ParameterReflection>|null
     */
    private function parametersOf(Node\Expr\FuncCall|Node\Expr\MethodCall|Node\Expr\StaticCall $node, Scope $scope): ?array
    {
        $reflection = null;

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $provider = $this->adapter->reflectionProvider();
            $reflection = $provider->hasFunction($node->name, $scope) ? $provider->getFunction($node->name, $scope) : null;
        } elseif ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $reflection = $scope->getMethodReflection($scope->getType($node->var), $node->name->toString());
        } elseif ($node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && $node->class instanceof Node\Name
        ) {
            $reflection = $scope->getMethodReflection(
                new ObjectType($scope->resolveName($node->class)),
                $node->name->toString(),
            );
        }

        return $reflection === null ? null : $reflection->getVariants()[0]->getParameters();
    }

    /**
     * @param  list<ParameterReflection>  $parameters
     */
    private static function parameterNamed(array $parameters, string $name): ?ParameterReflection
    {
        foreach ($parameters as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * A trailing variadic takes every argument past the declared ones — `sort(&...$arrays)` shapes.
     *
     * @param  list<ParameterReflection>  $parameters
     */
    private static function variadicTail(array $parameters): ?ParameterReflection
    {
        $last = $parameters === [] ? null : $parameters[count($parameters) - 1];

        return $last !== null && $last->isVariadic() ? $last : null;
    }
}
