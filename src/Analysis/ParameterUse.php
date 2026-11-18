<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\ConditionTree;
use Docuccino\Core\Inference\LocalWrites;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\TypeCondition;
use Docuccino\Inference\PhpStan\Support\SourceOrder;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use WeakMap;

/**
 * Reads what a callable body does with its own parameters: which parameter a return hands back unchanged
 * ({@see ReturnSite::$returnsParameter}), and which literal-argument calls on a parameter, and which
 * `instanceof` tests of one, a return's scope proves about the value the callable was handed. How
 * "unchanged" is judged is in docs/design/inference-embedding.md §4b.
 *
 * @internal
 */
final class ParameterUse
{
    /** Readers: nothing they return changes the value they are called on. */
    private const READER = '/^(get|is|has)[A-Z]/';

    /** Named as readers, and not: `isNotModified()` turns a response whose validators match into a bodiless 304. */
    private const WRITERS_NAMED_AS_READERS = ['isnotmodified'];

    /**
     * Functions that hand out the calling scope's locals or arguments without naming one. PHP refuses to call
     * any of them dynamically, so a call by name is the only spelling.
     */
    private const SCOPE_READERS = ['compact', 'debug_backtrace', 'func_get_arg', 'func_get_args', 'get_defined_vars'];

    /** Header-bag methods that only read. */
    private const HEADER_READERS = ['all', 'get', 'has', 'keys', 'count', 'contains'];

    /** Header-bag writes whose first argument is the header name. */
    private const HEADER_WRITES = ['set', 'remove'];

    /** @var WeakMap<Node, array{list<int>, Node\Stmt\Block, array<int, array{Node, string}>}>|null each body's parents, by its first statement */
    private static ?WeakMap $connected = null;

    /** @var WeakMap<Node, array<string, list<Node>>>|null each body's touches of a name, by its first statement */
    private static ?WeakMap $touching = null;

    /** @var WeakMap<Node, array<string, bool>>|null what a guard's operand was judged, by the question and the body */
    private static ?WeakMap $judged = null;

    /**
     * The parameter `$expr` returns unchanged, or null.
     *
     * @param  list<string>  $parameters
     * @param  list<Node>  $body
     */
    public static function echoed(?Node\Expr $expr, array $parameters, array $body): ?string
    {
        if (! $expr instanceof Node\Expr\Variable || ! is_string($expr->name) || ! in_array($expr->name, $parameters, true)) {
            return null;
        }

        return self::untouched($expr, $expr->name, $body, rebindsOnly: false) ? $expr->name : null;
    }

    /**
     * The parameters still naming the value the callable was handed where `$at` is reached: nothing that can
     * run first could have bound the name to another value. A scope's answer about any other parameter is
     * about whatever was bound to it since. What a call on the value changed is the scope's own to forget.
     *
     * @param  list<string>  $parameters
     * @param  list<Node>  $body
     * @return list<string>
     */
    public static function heldAt(Node $at, array $parameters, array $body): array
    {
        return array_values(array_filter($parameters, static fn (string $name): bool => self::untouched($at, $name, $body, rebindsOnly: true)));
    }

    /**
     * Every call on a parameter whose arguments are all string literals — the calls whose answer a return
     * site's scope can prove, since nothing but the receiver varies between two of them. Source order,
     * one per distinct call.
     *
     * @param  list<string>  $parameters
     * @param  list<Node>  $body
     * @return list<Node\Expr\MethodCall>
     */
    public static function literalCalls(array $parameters, array $body): array
    {
        $calls = [];
        foreach ((new NodeFinder)->findInstanceOf($body, Node\Expr\MethodCall::class) as $call) {
            $parameter = self::receiver($call);
            $arguments = self::literalArguments($call);
            if ($parameter === null || ! in_array($parameter, $parameters, true) || ! $call->name instanceof Node\Identifier || $arguments === null) {
                continue;
            }

            $key = $parameter."\0".$call->name->toString()."\0".implode("\0", $arguments);
            $calls[$key] ??= $call;
        }

        return self::inSourceOrder($calls);
    }

    /**
     * What `$scope` proves about each call on a parameter in `$held`: the ones it types as exactly one constant scalar.
     *
     * @param  list<Node\Expr\MethodCall>  $calls  from {@see literalCalls()}
     * @param  list<string>  $held  from {@see heldAt()}
     * @return list<CallCondition>
     */
    public static function conditionsAt(Scope $scope, array $calls, array $held): array
    {
        $conditions = [];
        foreach ($calls as $call) {
            $parameter = self::receiver($call);
            $arguments = self::literalArguments($call);
            if ($parameter === null || ! in_array($parameter, $held, true) || ! $call->name instanceof Node\Identifier || $arguments === null) {
                continue;
            }

            $values = $scope->getType($call)->getConstantScalarValues();
            if (count($values) === 1 && $values[0] !== null) {
                $conditions[] = new CallCondition($parameter, $call->name->toString(), $arguments, $values[0]);
            }
        }

        return $conditions;
    }

    /**
     * Every `instanceof` test of a parameter against a named class — the other kind of question a return
     * site's scope can answer outright. Source order, one per distinct parameter and class.
     *
     * @param  list<string>  $parameters
     * @param  list<Node>  $body
     * @return list<Node\Expr\Instanceof_>
     */
    public static function typeTests(array $parameters, array $body): array
    {
        $tests = [];
        foreach ((new NodeFinder)->findInstanceOf($body, Node\Expr\Instanceof_::class) as $test) {
            $parameter = self::tested($test);
            if ($parameter === null || ! in_array($parameter, $parameters, true) || ! $test->class instanceof Node\Name) {
                continue;
            }

            $tests[$parameter."\0".$test->class->toLowerString()] ??= $test;
        }

        return self::inSourceOrder($tests);
    }

    /**
     * What `$scope` proves about each test of a parameter in `$held`: the ones it types as exactly true or
     * exactly false. However the guard is spelled — negated, parenthesised, turned around, a ternary — the
     * scope it leaves is the answer.
     *
     * @param  list<Node\Expr\Instanceof_>  $tests  from {@see typeTests()}
     * @param  list<string>  $held  from {@see heldAt()}
     * @return list<TypeCondition>
     */
    public static function typeConditionsAt(Scope $scope, array $tests, array $held): array
    {
        $conditions = [];
        foreach ($tests as $test) {
            $parameter = self::tested($test);
            if ($parameter === null || ! in_array($parameter, $held, true) || ! $test->class instanceof Node\Name) {
                continue;
            }

            $answer = $scope->getType($test);
            if ($answer->isTrue()->yes() || $answer->isFalse()->yes()) {
                $conditions[] = new TypeCondition($parameter, $scope->resolveName($test->class), $answer->isTrue()->yes());
            }
        }

        return $conditions;
    }

    /**
     * What the guards on the way to `$at` require of the parameters, read off the source where a scope proves
     * only the whole: the condition of each `if` branch and ternary arm around it, and the negated condition of
     * each `if` before it whose every branch leaves the function. Joined by `&&`, `||` and `!` as written; a
     * condition other than a literal-argument call or an `instanceof` test of a parameter still holding what
     * it was handed there is unknown. Null where nothing is required.
     *
     * @param  list<string>  $parameters
     * @param  list<Node>  $body
     */
    public static function reachedWhen(Node $at, Scope $scope, array $parameters, array $body): ?ConditionTree
    {
        $tree = ConditionTree::all(array_map(
            static function (array $guard) use ($scope, $parameters, $body): ConditionTree {
                $condition = self::condition($guard[0], $scope, $parameters, $body);

                return $guard[1] ? $condition : ConditionTree::not($condition);
            },
            self::guardsTo($at, $body),
        ));

        return $tree->constrains() ? $tree : null;
    }

    /**
     * Every condition on the way to `$at`, each with the value it holds there: the condition of each `if`
     * branch and ternary arm around it, the negated condition of each `if` before it whose every branch leaves
     * the function, and the left of a short circuit whose right it is in — `$a ?? <at>` holds `$a === null`,
     * `$a || <at>` holds `$a` false, `$a && <at>` holds it true. The one grammar for what a path requires,
     * whether a return or a throw is asked about. `$at` must be a node of `$body` itself; a nested function's
     * path is its own.
     *
     * @param  list<Node>  $body
     * @return list<array{Node\Expr, bool}>
     */
    public static function guardsTo(Node $at, array $body): array
    {
        [$root, $parents] = self::connected($body);

        /** @var list<array{Node\Expr, bool}> $guards */
        $guards = [];
        $node = $at;
        while (isset($parents[spl_object_id($node)])) {
            [$parent, $slot] = $parents[spl_object_id($node)];
            if ($parent instanceof Node\FunctionLike) {
                break;
            }

            array_push($guards, ...match (true) {
                $parent instanceof Node\Stmt\If_ && $slot === 'stmts', $parent instanceof Node\Stmt\ElseIf_ && $slot === 'stmts' => [[$parent->cond, true]],
                $parent instanceof Node\Stmt\If_ && ($slot === 'elseifs' || $slot === 'else') => self::passedOver($parent, $node),
                $parent instanceof Node\Expr\Ternary && $slot === 'if' => [[$parent->cond, true]],
                $parent instanceof Node\Expr\Ternary && $slot === 'else' => [[$parent->cond, false]],
                $parent instanceof Node\Expr\BinaryOp\Coalesce && $slot === 'right' => [[self::isNull($parent->left), true]],
                $parent instanceof Node\Expr\AssignOp\Coalesce && $slot === 'expr' => [[self::isNull($parent->var), true]],
                ($parent instanceof Node\Expr\BinaryOp\BooleanOr || $parent instanceof Node\Expr\BinaryOp\LogicalOr) && $slot === 'right' => [[$parent->left, false]],
                ($parent instanceof Node\Expr\BinaryOp\BooleanAnd || $parent instanceof Node\Expr\BinaryOp\LogicalAnd) && $slot === 'right' => [[$parent->left, true]],
                default => [],
            });

            $siblings = $parent === $root ? $body : ($node instanceof Node\Stmt ? $parent->{$slot} : null);
            if (is_array($siblings)) {
                foreach (array_slice($siblings, 0, (int) array_search($node, $siblings, true)) as $before) {
                    if ($before instanceof Node\Stmt\If_ && self::leavesWhenTaken($before)) {
                        array_push($guards, ...self::passedOver($before, null));
                    }
                }
            }

            $node = $parent;
        }

        return $guards;
    }

    /** `$expr === null`, the test a `??` makes of its left, positioned where the left is. */
    private static function isNull(Node\Expr $expr): Node\Expr\BinaryOp\Identical
    {
        return new Node\Expr\BinaryOp\Identical($expr, new Node\Expr\ConstFetch(new Node\Name('null')), $expr->getAttributes());
    }

    /**
     * The conditions an `if` chain has tested false by the time control passes into `$branch` — an `elseif` or
     * its `else` — or, where `$branch` is null, past the whole chain.
     *
     * @return list<array{Node\Expr, bool}>
     */
    private static function passedOver(Node\Stmt\If_ $if, ?Node $branch): array
    {
        $passed = [[$if->cond, false]];
        foreach ($if->elseifs as $elseif) {
            if ($elseif === $branch) {
                break;
            }
            $passed[] = [$elseif->cond, false];
        }

        return $passed;
    }

    /** Whether every branch of an `if` with no `else` ends by leaving the function. */
    private static function leavesWhenTaken(Node\Stmt\If_ $if): bool
    {
        if ($if->else !== null) {
            return false;
        }

        foreach ([$if->stmts, ...array_map(static fn (Node\Stmt\ElseIf_ $e): array => $e->stmts, $if->elseifs)] as $stmts) {
            $last = $stmts === [] ? null : $stmts[array_key_last($stmts)];
            if (! $last instanceof Node\Stmt\Return_
                && ! ($last instanceof Node\Stmt\Expression && ($last->expr instanceof Node\Expr\Throw_ || $last->expr instanceof Node\Expr\Exit_))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * One condition as a {@see ConditionTree}: `&&`, `||` and `!` kept, and each operand a literal-argument call
     * on a parameter — on its own, or compared with a literal — or an `instanceof` test of one. A call counts
     * only where nothing before it could have changed the value it is called on, a test where nothing could
     * have rebound the name: what the host answers is the value as it was handed.
     *
     * @param  list<string>  $parameters
     * @param  list<Node>  $body
     */
    private static function condition(Node\Expr $expr, Scope $scope, array $parameters, array $body): ConditionTree
    {
        if ($expr instanceof Node\Expr\BooleanNot) {
            return ConditionTree::not(self::condition($expr->expr, $scope, $parameters, $body));
        }
        if ($expr instanceof Node\Expr\BinaryOp\BooleanAnd || $expr instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return ConditionTree::all([self::condition($expr->left, $scope, $parameters, $body), self::condition($expr->right, $scope, $parameters, $body)]);
        }
        if ($expr instanceof Node\Expr\BinaryOp\BooleanOr || $expr instanceof Node\Expr\BinaryOp\LogicalOr) {
            return ConditionTree::any([self::condition($expr->left, $scope, $parameters, $body), self::condition($expr->right, $scope, $parameters, $body)]);
        }

        if ($expr instanceof Node\Expr\Instanceof_) {
            $parameter = self::tested($expr);

            return $parameter !== null && in_array($parameter, $parameters, true) && $expr->class instanceof Node\Name
                && self::judged($expr, 'untouched '.$parameter, $body, static fn (): bool => self::untouched($expr, $parameter, $body, rebindsOnly: true))
                ? ConditionTree::leaf(new TypeCondition($parameter, $scope->resolveName($expr->class), true))
                : ConditionTree::known(null);
        }

        $negated = $expr instanceof Node\Expr\BinaryOp\NotIdentical || $expr instanceof Node\Expr\BinaryOp\NotEqual;
        [$call, $value] = $expr instanceof Node\Expr\MethodCall ? [$expr, true] : [null, null];
        if ($negated || $expr instanceof Node\Expr\BinaryOp\Identical || $expr instanceof Node\Expr\BinaryOp\Equal) {
            [$call, $value] = match (true) {
                $expr->left instanceof Node\Expr\MethodCall => [$expr->left, self::literal($expr->right, $expr)],
                $expr->right instanceof Node\Expr\MethodCall => [$expr->right, self::literal($expr->left, $expr)],
                default => [null, null],
            };
        }

        $parameter = $call === null ? null : self::receiver($call);
        $arguments = $call === null ? null : self::literalArguments($call);
        if ($call === null || $value === null || $parameter === null || $arguments === null
            || ! $call->name instanceof Node\Identifier || ! in_array($parameter, $parameters, true)
            || ! self::judged($call, 'unchanged '.$parameter, $body, static fn (): bool => self::unchangedBefore($call, $parameter, $body))
        ) {
            return ConditionTree::known(null);
        }

        $leaf = ConditionTree::leaf(new CallCondition($parameter, $call->name->toString(), $arguments, $value));

        return $negated ? ConditionTree::not($leaf) : $leaf;
    }

    /**
     * What `$judge` answers of an operand of a guard, judged once per body: the answer stands where the guard
     * does, so it is the same for every return past it.
     *
     * @param  list<Node>  $body
     * @param  callable(): bool  $judge
     */
    private static function judged(Node $operand, string $question, array $body, callable $judge): bool
    {
        $key = $question.'@'.($body === [] ? '' : spl_object_id($body[0]).'+'.count($body));
        $answers = (self::$judged ??= new WeakMap)[$operand] ?? [];
        if (! isset($answers[$key])) {
            $answers[$key] = $judge();
            self::$judged[$operand] = $answers;
        }

        return $answers[$key];
    }

    /**
     * Whether nothing that can run before `$at` could have changed the value `$name` holds, reading a
     * literal-argument call on it inside another guard's condition as the question it is read as here.
     *
     * @param  list<Node>  $body
     */
    private static function unchangedBefore(Node $at, string $name, array $body): bool
    {
        return self::nonePrecedes($at, $body, static fn (array $parents, Node $root): array => array_values(array_filter(
            self::touches($name, $body, $parents, $root, rebindsOnly: false),
            static fn (Node $touch): bool => ! ($touch instanceof Node\Expr\MethodCall && self::literalArguments($touch) !== null && self::inCondition($touch, $parents)),
        )));
    }

    /**
     * Whether the node is part of an `if`, `elseif` or ternary condition, below any statement.
     *
     * @param  array<int, array{Node, string}>  $parents
     */
    private static function inCondition(Node $node, array $parents): bool
    {
        while (isset($parents[spl_object_id($node)])) {
            [$parent, $slot] = $parents[spl_object_id($node)];
            if ($slot === 'cond' && ($parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_ || $parent instanceof Node\Expr\Ternary)) {
                return true;
            }
            if ($parent instanceof Node\Stmt) {
                return false;
            }
            $node = $parent;
        }

        return false;
    }

    /** The literal one side of a comparison names: an integer, or under `===` / `!==` a string or a boolean too. */
    private static function literal(Node\Expr $side, Node\Expr\BinaryOp $comparison): int|string|bool|null
    {
        $strict = $comparison instanceof Node\Expr\BinaryOp\Identical || $comparison instanceof Node\Expr\BinaryOp\NotIdentical;

        return match (true) {
            $side instanceof Node\Scalar\Int_ => $side->value,
            $strict && $side instanceof Node\Scalar\String_ => $side->value,
            $strict && $side instanceof Node\Expr\ConstFetch && in_array($side->name->toLowerString(), ['true', 'false'], true) => $side->name->toLowerString() === 'true',
            default => null,
        };
    }

    /**
     * @template T of Node
     *
     * @param  array<string, T>  $nodes
     * @return list<T>
     */
    private static function inSourceOrder(array $nodes): array
    {
        $nodes = array_values($nodes);
        usort($nodes, static fn (Node $a, Node $b): int => SourceOrder::of($a) <=> SourceOrder::of($b));

        return $nodes;
    }

    /**
     * Whether nothing that can run before `$at` is evaluated could have changed the value `$name` holds —
     * or, `$rebindsOnly`, bound the name to another value.
     *
     * @param  list<Node>  $body
     */
    private static function untouched(Node $at, string $name, array $body, bool $rebindsOnly): bool
    {
        return self::nonePrecedes($at, $body, static fn (array $parents, Node $root): array => self::touches($name, $body, $parents, $root, $rebindsOnly));
    }

    /**
     * Whether `$this->{$property}` still holds what it held on entry where `$at` is reached: nothing that can
     * run first assigns it, binds it in a `foreach`, takes a reference to it, or unsets it. A call writing it from elsewhere is past
     * what a body read sees, as a callee writing back through a by-reference argument is for a parameter.
     *
     * @param  list<Node>  $body
     */
    public static function propertyHeldAt(Node $at, string $property, array $body): bool
    {
        return self::nonePrecedes($at, $body, static fn (): array => array_values((new NodeFinder)->find($body, static function (Node $node) use ($property): bool {
            $written = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef => [$node->var],
                $node instanceof Node\Stmt\Unset_ => $node->vars,
                $node instanceof Node\Stmt\Foreach_ => array_filter([$node->keyVar, $node->valueVar]),
                $node instanceof Node\ArrayItem && $node->byRef => [$node->value],
                default => [],
            };
            if ($node instanceof Node\Expr\AssignRef) {
                $written[] = $node->expr;
            }

            while ($written !== []) {
                $target = array_shift($written);
                if ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
                    foreach ($target->items as $item) {
                        if ($item !== null) {
                            $written[] = $item->value;
                        }
                    }
                } elseif (self::isOwnProperty($target, $property)) {
                    return true;
                }
            }

            return false;
        })));
    }

    private static function isOwnProperty(Node $node, string $property): bool
    {
        return ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
            && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier && $node->name->toString() === $property;
    }

    /**
     * Whether no node the `$touches` callback names, over the body connected to one root, can run before `$at`.
     *
     * @param  list<Node>  $body
     * @param  callable(array<int, array{Node, string}>, Node): list<Node>  $touches
     */
    private static function nonePrecedes(Node $at, array $body, callable $touches): bool
    {
        [$root, $parents] = self::connected($body);
        foreach ($touches($parents, $root) as $touch) {
            if (self::mayPrecede($touch, $at, $parents, $root, $body)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every node that may change the value `$name` holds: a write to or through it, a call on it that is not
     * a reader, any other use of it at all — an argument, a capture, an alias — since an object is a handle
     * and code this does not read may write through it, and any reach into the scope that never names it.
     * What it cannot see is a callee reaching back up the call stack for its caller's arguments.
     *
     * `$rebindsOnly` keeps the nodes that may bind the name to another value: a write to it, and a reference
     * taken to it — `use (&…)`, `[&…]`. Like {@see LocalWrites}, it does not see a callee taking an argument
     * by reference, which would read every `report($e)` before a return as a rebinding.
     *
     * @param  list<Node>  $body
     * @param  array<int, array{Node, string}>  $parents
     * @return list<Node>
     */
    private static function touches(string $name, array $body, array $parents, Node $root, bool $rebindsOnly): array
    {
        // The same for wherever it is asked from, so found once per body.
        $first = $body[0] ?? null;
        $key = $name.($rebindsOnly ? '#rebinds' : '#touches').'+'.count($body);
        $found = $first === null ? null : ((self::$touching ??= new WeakMap)[$first] ?? [])[$key] ?? null;
        if ($found !== null) {
            return $found;
        }

        $found = array_values((new NodeFinder)->find($body, static function (Node $node) use ($name, $parents, $root, $rebindsOnly): bool {
            $assignment = LocalWrites::assignment($node);
            if (($assignment !== null && $assignment[0] === $name)
                || in_array($name, LocalWrites::retires($node), true)
                || LocalWrites::retiresEveryLocal($node)
            ) {
                return true;
            }

            if ($rebindsOnly) {
                return self::referenced($node, $name);
            }

            // Reaching it without its name: a variable variable, or a function handing out the whole scope.
            if (($node instanceof Node\Expr\Variable && ! is_string($node->name))
                || ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array($node->name->toLowerString(), self::SCOPE_READERS, true))
            ) {
                return true;
            }

            // Writing THROUGH it — a property or an offset — changes the value it names.
            if (($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef)
                && ! $node->var instanceof Node\Expr\Variable
                && self::rootedAt($node->var, $name)
            ) {
                return true;
            }

            if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
                return self::mutates($node, $name);
            }

            return $node instanceof Node\Expr\Variable && $node->name === $name && ! self::readsInPlace($node, $parents, $root);
        }));
        if ($first !== null) {
            self::$touching ??= new WeakMap;
            self::$touching[$first] = [$key => $found] + (self::$touching[$first] ?? []);
        }

        return $found;
    }

    /** Whether the node takes a reference to the variable: a by-reference capture or array item. */
    private static function referenced(Node $node, string $name): bool
    {
        $target = match (true) {
            $node instanceof Node\ClosureUse && $node->byRef => $node->var,
            $node instanceof Node\ArrayItem && $node->byRef => $node->value,
            default => null,
        };

        return $target instanceof Node\Expr\Variable && $target->name === $name;
    }

    /**
     * Whether an occurrence of the variable is one {@see touches()} decides elsewhere or that cannot change
     * it: the receiver of a call or of `->headers->…()` ({@see mutates()}), an `instanceof` operand, or the
     * value itself handed out by a `return` — through any ternary or `match` branch on the way.
     *
     * @param  array<int, array{Node, string}>  $parents
     */
    private static function readsInPlace(Node\Expr\Variable $variable, array $parents, Node $root): bool
    {
        [$parent, $slot] = $parents[spl_object_id($variable)] ?? [null, ''];

        if (($parent instanceof Node\Expr\MethodCall || $parent instanceof Node\Expr\NullsafeMethodCall) && $slot === 'var') {
            return true;
        }

        if ($parent instanceof Node\Expr\PropertyFetch && $slot === 'var' && $parent->name instanceof Node\Identifier && $parent->name->toString() === 'headers') {
            [$grandparent, $grandslot] = $parents[spl_object_id($parent)] ?? [null, ''];

            return ($grandparent instanceof Node\Expr\MethodCall || $grandparent instanceof Node\Expr\NullsafeMethodCall) && $grandslot === 'var';
        }

        if ($parent instanceof Node\Expr\Instanceof_ && $slot === 'expr') {
            return true;
        }

        // Handed out: up through branches of a returned value, to the `return` — or to the body's root, an
        // arrow function's expression.
        $node = $variable;
        while (true) {
            [$parent, $slot] = $parents[spl_object_id($node)] ?? [null, ''];
            if ($parent === null || $parent === $root || ($parent instanceof Node\Stmt\Return_ && $slot === 'expr')) {
                return true;
            }

            $branch = ($parent instanceof Node\Expr\Ternary && $slot !== 'cond')
                || ($parent instanceof Node\MatchArm && $slot === 'body')
                || ($parent instanceof Node\Expr\Match_ && $slot === 'arms');
            if (! $branch) {
                return false;
            }

            $node = $parent;
        }
    }

    /**
     * Whether `$touch` can run before `$at` is evaluated. It cannot where it sits inside `$at` itself, in a
     * branch exclusive of `$at`'s, where the function has already left by then — the touch's own `return`,
     * or one after it on its way out — or where it is written after `$at` with no loop around both; a
     * `finally` runs it anyway.
     *
     * @param  array<int, array{Node, string}>  $parents
     * @param  list<Node>  $body
     */
    private static function mayPrecede(Node $touch, Node $at, array $parents, Node $root, array $body): bool
    {
        $touchPath = self::ancestry($touch, $parents);
        $returnPath = self::ancestry($at, $parents);

        $common = null;
        foreach ($touchPath as $candidate) {
            if (in_array($candidate, $returnPath, true)) {
                $common = $candidate;

                break;
            }
        }

        // Evaluated as part of `$at`, so after it is reached.
        if ($common === $at) {
            return false;
        }

        // Not found in one tree, or `$at` inside the touch: nothing to order them by.
        if ($common === null || $common === $touch) {
            return true;
        }

        $below = array_slice($touchPath, 0, (int) array_search($common, $touchPath, true));
        foreach ($below as $around) {
            if ($around instanceof Node\Stmt\Finally_) {
                return true;
            }
        }

        if (self::exitsFirst($below, $common, self::childToward($returnPath, $common), $parents, $root, $body)) {
            return false;
        }

        if (self::inLoop($common, $parents)) {
            return true;
        }

        if (self::exclusive($common, $touchPath, $returnPath, $parents)) {
            return false;
        }

        return SourceOrder::of($touch) < SourceOrder::of($at);
    }

    /**
     * Whether the function has left by the time control could pass from the touch toward the return: the
     * touch is in a `return` of its own, or a statement list on its way up ends it — a `return`, a `throw`
     * or an `exit` after the touch, and before the return's branch where the list is the common one. A `try`
     * with a catch around the touch undoes that: whatever it or the rest of the block throws lands in the
     * catch, which may go on to the return — unless the return is in that same block with no loop around.
     *
     * @param  list<Node>  $below  the touch's path up to, not including, `$common`
     * @param  array<int, array{Node, string}>  $parents
     * @param  list<Node>  $body  the statements under `$root`, which holds no list of its own
     */
    private static function exitsFirst(array $below, Node $common, Node $towardReturn, array $parents, Node $root, array $body): bool
    {
        $left = false;
        foreach ($below as $node) {
            [$parent, $slot] = $parents[spl_object_id($node)] ?? [null, ''];
            $siblings = $parent === null ? null : ($parent === $root ? $body : $parent->{$slot});

            $left = $left || $node instanceof Node\Stmt\Return_ || ($node instanceof Node\Stmt && is_array($siblings) && self::leavesAfter($node, $siblings, $parent === $common ? $towardReturn : null));

            if ($parent instanceof Node\Stmt\TryCatch && $slot === 'stmts' && $parent->catches !== []) {
                $returnInBlock = $parent === $common && ($parents[spl_object_id($towardReturn)] ?? [null, ''])[1] === 'stmts';
                $left = $left && $returnInBlock && ! self::inLoop($parent, $parents);
            }
        }

        return $left;
    }

    /**
     * Whether a statement after `$node` in its list, and before `$stop` where given, ends the function.
     *
     * @param  array<mixed>  $siblings
     */
    private static function leavesAfter(Node $node, array $siblings, ?Node $stop): bool
    {
        $from = (int) array_search($node, $siblings, true) + 1;
        $index = $stop === null ? false : array_search($stop, $siblings, true);
        $to = is_int($index) ? $index : count($siblings);

        for ($i = $from; $i < $to; $i++) {
            $sibling = $siblings[$i];
            if ($sibling instanceof Node\Stmt\Return_
                || ($sibling instanceof Node\Stmt\Expression && ($sibling->expr instanceof Node\Expr\Throw_ || $sibling->expr instanceof Node\Expr\Exit_))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{Node, string}>  $parents
     */
    private static function inLoop(Node $node, array $parents): bool
    {
        foreach (self::ancestry($node, $parents) as $around) {
            if ($around instanceof Node\Stmt\For_ || $around instanceof Node\Stmt\Foreach_ || $around instanceof Node\Stmt\While_ || $around instanceof Node\Stmt\Do_) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the touch and the return sit in branches of `$common` only one of which runs.
     *
     * @param  list<Node>  $touchPath
     * @param  list<Node>  $returnPath
     * @param  array<int, array{Node, string}>  $parents
     */
    private static function exclusive(Node $common, array $touchPath, array $returnPath, array $parents): bool
    {
        $a = self::childToward($touchPath, $common);
        $b = self::childToward($returnPath, $common);
        $slotA = ($parents[spl_object_id($a)] ?? [null, ''])[1];
        $slotB = ($parents[spl_object_id($b)] ?? [null, ''])[1];

        if ($common instanceof Node\Expr\Ternary) {
            return $slotA !== 'cond' && $slotB !== 'cond' && $slotA !== $slotB;
        }

        // `stmts` is one branch, each `elseif` another, `else` the last; the condition runs before them all.
        if ($common instanceof Node\Stmt\If_) {
            $branch = static fn (Node $child, string $slot): string => $slot === 'elseifs' ? 'elseif#'.spl_object_id($child) : $slot;

            return $slotA !== 'cond' && $slotB !== 'cond' && $branch($a, $slotA) !== $branch($b, $slotB);
        }

        // Two arms of one `match`, the touch in its arm's body: an arm's conditions are tried on the way past.
        if ($common instanceof Node\Expr\Match_ && $a instanceof Node\MatchArm && $b instanceof Node\MatchArm) {
            return ($parents[spl_object_id(self::childToward($touchPath, $a))] ?? [null, ''])[1] === 'body';
        }

        return false;
    }

    /**
     * The node and every ancestor above it, nearest first.
     *
     * @param  array<int, array{Node, string}>  $parents
     * @return list<Node>
     */
    private static function ancestry(Node $node, array $parents): array
    {
        $path = [$node];
        while (isset($parents[spl_object_id($node)])) {
            $node = $parents[spl_object_id($node)][0];
            $path[] = $node;
        }

        return $path;
    }

    /**
     * The member of `$path` directly below `$ancestor`.
     *
     * @param  list<Node>  $path
     */
    private static function childToward(array $path, Node $ancestor): Node
    {
        $index = array_search($ancestor, $path, true);

        return is_int($index) && $index > 0 ? $path[$index - 1] : $ancestor;
    }

    /**
     * The body connected under one root, so any two of its nodes have an ancestor in common, with every node's
     * parent and the slot it sits in; connected once per body.
     *
     * @param  list<Node>  $body
     * @return array{Node\Stmt\Block, array<int, array{Node, string}>}
     */
    private static function connected(array $body): array
    {
        $statements = array_map(spl_object_id(...), $body);
        $first = $body[0] ?? null;
        $cached = $first === null ? null : (self::$connected ??= new WeakMap)[$first] ?? null;
        if ($cached !== null && $cached[0] === $statements) {
            return [$cached[1], $cached[2]];
        }

        $root = new Node\Stmt\Block([]);
        $parents = [];
        foreach ($body as $node) {
            $parents[spl_object_id($node)] = [$root, 'stmts'];
            self::connect($node, $parents);
        }
        if ($first !== null) {
            self::$connected ??= new WeakMap;
            self::$connected[$first] = [$statements, $root, $parents];
        }

        return [$root, $parents];
    }

    /**
     * Records every descendant's parent and the sub-node slot it sits in.
     *
     * @param  array<int, array{Node, string}>  $parents
     */
    private static function connect(Node $node, array &$parents): void
    {
        foreach ($node->getSubNodeNames() as $slot) {
            $children = $node->{$slot};
            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $parents[spl_object_id($child)] = [$node, $slot];
                    self::connect($child, $parents);
                }
            }
        }
    }

    private static function mutates(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, string $name): bool
    {
        $method = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

        // `$param->method(…)`
        if ($call->var instanceof Node\Expr\Variable && $call->var->name === $name) {
            if ($method === null) {
                return true;
            }

            return (preg_match(self::READER, $method) !== 1 || in_array(strtolower($method), self::WRITERS_NAMED_AS_READERS, true))
                && ! ($method === 'header' && self::namesAHeaderOtherThanContentType($call));
        }

        // `$param->headers->method(…)`
        if ($call->var instanceof Node\Expr\PropertyFetch
            && $call->var->var instanceof Node\Expr\Variable
            && $call->var->var->name === $name
            && $call->var->name instanceof Node\Identifier
            && $call->var->name->toString() === 'headers'
        ) {
            if ($method === null) {
                return true;
            }

            return ! in_array($method, self::HEADER_READERS, true)
                && ! (in_array($method, self::HEADER_WRITES, true) && self::namesAHeaderOtherThanContentType($call));
        }

        return false;
    }

    /** Whether a header write's first argument is a literal name that is not the one setting the media type. */
    private static function namesAHeaderOtherThanContentType(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call): bool
    {
        $first = $call->getArgs()[0] ?? null;

        return $first !== null
            && $first->value instanceof Node\Scalar\String_
            && strcasecmp($first->value->value, 'content-type') !== 0;
    }

    private static function rootedAt(Node\Expr $target, string $name): bool
    {
        while ($target instanceof Node\Expr\PropertyFetch
            || $target instanceof Node\Expr\NullsafePropertyFetch
            || $target instanceof Node\Expr\ArrayDimFetch
        ) {
            $target = $target->var;
        }

        return $target instanceof Node\Expr\Variable && $target->name === $name;
    }

    private static function receiver(Node\Expr\MethodCall $call): ?string
    {
        return $call->var instanceof Node\Expr\Variable && is_string($call->var->name) ? $call->var->name : null;
    }

    private static function tested(Node\Expr\Instanceof_ $test): ?string
    {
        return $test->expr instanceof Node\Expr\Variable && is_string($test->expr->name) ? $test->expr->name : null;
    }

    /**
     * @return list<string>|null null where any argument is not a plain string literal
     */
    private static function literalArguments(Node\Expr\MethodCall $call): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $arguments = [];
        foreach ($call->getArgs() as $arg) {
            if ($arg->unpack || $arg->name !== null || ! $arg->value instanceof Node\Scalar\String_) {
                return null;
            }
            $arguments[] = $arg->value->value;
        }

        return $arguments;
    }
}
