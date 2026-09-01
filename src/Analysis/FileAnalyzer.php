<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\LocalWrites;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\ObjectType;
use Throwable;

/**
 * Parses a file once and harvests everything the engine reads out of it: the virtual
 * `MethodReturnStatementsNode`s — the node that pairs every `return` with its flow-refined scope and carries
 * the method's throw points — plus the closures and the local assignments. Memoised per file so descent
 * reuses one rich parse; the adapter's priming is what keeps the bodies from being stripped.
 *
 * @phpstan-type FileHarvest array{
 *     methods: array<string, MethodReturnStatementsNode>,
 *     closures: array<int, ClosureReturnStatementsNode>,
 *     arrays: array<string, array<string, Node\Expr\Array_>>,
 *     locals: array<string, array<string, array{Node\Expr, Scope}|null>>,
 *     calls: array<int, Scope>,
 * }
 *
 * @internal
 */
final class FileAnalyzer
{
    /** @var array<string, FileHarvest> */
    private array $cache = [];

    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly FileWalks $walks,
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

    /**
     * Keyed by start line — how an exception-handler render callback is located, since `ReflectionFunction`
     * gives us file+line and nothing else.
     *
     * @return array<int, ClosureReturnStatementsNode>
     */
    public function closures(string $file): array
    {
        return $this->harvest($file)['closures'];
    }

    /**
     * The scope PHPStan resolved AT one construction or static call, or null where the file holds no such
     * call at that position. Keyed by start offset rather than by node identity: the same file parses to
     * fresh nodes whenever a recorded walk has been discarded, and an offset survives that where an object
     * handle does not.
     *
     * This is the scope a reader folding one ARGUMENT of that call must use. The method's end scope answers
     * a different question — what a variable holds once the body has finished — and a constructor that
     * reassigns its status parameter after forwarding it makes the two disagree, with the end scope naming
     * a value the callee never received.
     */
    public function scopeAtCall(string $file, Node\Expr\New_|Node\Expr\StaticCall $call): ?Scope
    {
        $position = $call->getStartFilePos();

        return $position < 0 ? null : ($this->harvest($file)['calls'][$position] ?? null);
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
        /** @var array<int, ClosureReturnStatementsNode> $closures */
        $closures = [];
        /** @var array<string, array<string, Node\Expr\Array_>> $arrays */
        $arrays = [];
        /** @var array<string, array<string, array{Node\Expr, Scope}|null>> $locals */
        $locals = [];
        /** @var array<int, Scope> $calls the scope at each construction/static call, by start offset */
        $calls = [];
        /** @var array<string, true> $opaque scopes where a write named no single local */
        $opaque = [];

        $this->walks->walk($file, function (Node $node, Scope $scope) use (&$methods, &$closures, &$arrays, &$locals, &$calls, &$opaque): void {
            // Watching for these virtual nodes is the sanctioned way to pair returns with refined scope.
            // Collected first and outside the guard below, so that a reader wanting only a method body — the
            // throw analyzer, the tracer descending into a callee — never pays for the write half's failures.
            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof MethodReturnStatementsNode) {
                $methods[$node->getClassReflection()->getName().'::'.$node->getMethodName()] = $node;
            }

            // @phpstan-ignore phpstanApi.instanceofAssumption
            if ($node instanceof ClosureReturnStatementsNode) {
                $closures[$node->getClosureExpr()->getStartLine()] = $node;
            }

            // The two call forms whose arguments a status read folds ({@see scopeAtCall()}), paired with the
            // scope they are evaluated in. Collected here rather than on a walk of their own, so the pairing
            // is the same walk's and costs nothing beyond the entry.
            if (($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall)
                && $node->getStartFilePos() >= 0
            ) {
                $calls[$node->getStartFilePos()] = $scope;
            }

            $key = null;

            try {
                $key = self::scopeKey($scope);
                if ($key === null) {
                    return; // outside any function, where there are no locals to harvest
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

        return $this->cache[$normalised] = [
            'methods' => $methods,
            'closures' => $closures,
            'arrays' => $arrays,
            'locals' => $locals,
            'calls' => $calls,
        ];
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
