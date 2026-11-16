<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Inference\PhpStan\Support\ParsedFiles;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

/**
 * Whether a call leaves the object a variable holds as it was: nothing the call runs writes one of the
 * object's own properties. Read off the bodies the call reaches, so a reader taking arguments
 * (`$response->getData(true)`), or a helper handed the object that only reads it, keeps what was read off
 * the object before. A body this cannot read, a callee it cannot name, or the object kept or handed where it
 * cannot follow, may have changed it. A callee that returns the object hands it back to its caller, so its
 * call keeps it only where the caller drops what it returns or returns that in turn (`->header()` written as
 * a statement of its own).
 *
 * @internal
 */
final class StateKeepingCalls
{
    /** How many calls below the first a body is still followed. */
    private const DEPTH = 4;

    /** Something the body runs may have changed the object, or keeps it somewhere this does not follow. */
    private const CHANGED = 0;

    /** Nothing the body runs changes the object. */
    private const KEPT = 1;

    /** Nothing the body runs changes the object, and the body returns it. */
    private const HANDED_BACK = 2;

    /** @var array<string, array{self::CHANGED|self::KEPT|self::HANDED_BACK, list<string>}> an answer by `class::method#variable@held^depth`, with the files it was read out of */
    private array $memo = [];

    /** @var array<string, bool> the bodies being read, which a call back into adds nothing to, by whether one did */
    private array $reading = [];

    /** @var list<string> the bodies a call back into was answered for while they were being read */
    private array $assumed = [];

    /** @var list<string> the files the answer in flight has read, whatever it turns out to be */
    private array $read = [];

    public function __construct(private readonly ParsedFiles $files = new ParsedFiles) {}

    /**
     * For a variable a call is made on (`$argument` null) or hands on as `$argument`: whether the call keeps
     * the object it holds, and every file that answer was read out of — a "no" too, since an edit to what was
     * read can turn it into a "yes".
     *
     * @return array{bool, list<string>}
     */
    public function atCall(Node\Expr\CallLike $call, ?Node\Arg $argument, Scope $scope): array
    {
        $this->read = [];
        $kept = $this->keptAt($call, $argument, $scope);

        return [$kept, array_values(array_unique($this->read))];
    }

    private function keptAt(Node\Expr\CallLike $call, ?Node\Arg $argument, Scope $scope): bool
    {
        $variable = $argument === null ? ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall ? $call->var : null) : $argument->value;
        $held = $variable === null || $call->isFirstClassCallable() ? [] : $scope->getType($variable)->getObjectClassNames();
        if ($held === []) {
            return false;
        }

        $callees = $argument === null ? [] : $this->scopedCallees($call, $scope);
        foreach ($held as $class) {
            $targets = $argument === null ? $this->receivedBy($call, $class) : $callees;
            if ($targets === null || $targets === []) {
                return false;
            }

            foreach ($targets as $method) {
                $parameter = $argument === null ? 'this' : self::handedTo($method, $call, $argument);
                // What the call returns is the analysed code's own to keep, which this does not follow.
                if ($parameter === null || $this->keeps($method, $parameter, $class, 0) !== self::KEPT) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether every mention of `$variable` in the method's body leaves the `$held` object it holds as it was,
     * and whether the body returns it.
     *
     * @return self::CHANGED|self::KEPT|self::HANDED_BACK
     */
    private function keeps(ReflectionMethod $method, string $variable, string $held, int $depth): int
    {
        $key = $method->class.'::'.$method->name.'#'.$variable.'@'.$held;
        // A body reached by many paths is read once for each depth it is reached at, which bounds what it can follow.
        $remembered = $key.'^'.$depth;
        if (isset($this->memo[$remembered])) {
            array_push($this->read, ...$this->memo[$remembered][1]);

            return $this->memo[$remembered][0];
        }
        if (isset($this->reading[$key])) {
            $this->reading[$key] = true;
            $this->assumed[] = $key;

            return self::KEPT;
        }
        if ($depth > self::DEPTH) {
            return self::CHANGED;
        }

        $this->reading[$key] = false;
        $from = count($this->read);
        $assumedFrom = count($this->assumed);

        try {
            $kept = $this->body($method, $variable, $held, $depth);
            // A call back into a body that hands the object back hands it back too, wherever that call sits.
            $kept = $kept === self::HANDED_BACK && $this->reading[$key] ? self::CHANGED : $kept;
        } finally {
            unset($this->reading[$key]);
        }

        // An answer resting on what a call back into a body still being read was taken to do is not its own.
        $own = true;
        foreach (array_slice($this->assumed, $assumedFrom) as $on) {
            $own = $own && ! isset($this->reading[$on]);
        }
        if ($own) {
            $this->memo[$remembered] = [$kept, array_slice($this->read, $from)];
        }
        if ($this->reading === []) {
            $this->assumed = [];
        }

        return $kept;
    }

    /**
     * @return self::CHANGED|self::KEPT|self::HANDED_BACK
     *
     * @phpstan-impure
     */
    private function body(ReflectionMethod $method, string $variable, string $held, int $depth): int
    {
        $file = $method->getFileName();
        $body = $this->files->body($method);
        if ($file === false || $body === null) {
            return self::CHANGED;
        }

        $this->read[] = $file;
        $finder = new NodeFinder;
        $declaring = $method->getDeclaringClass();
        $mentions = [];
        foreach ($finder->find($body, static fn (Node $node): bool => self::names($node, $variable)) as $mention) {
            $mentions[spl_object_id($mention)] = true;
        }

        $calls = [];
        $dropped = [];
        $returned = [];
        foreach ($finder->find($body, static fn (Node $node): bool => true) as $node) {
            foreach (self::writeTargets($node) as $target) {
                if (self::names(self::root($target), $variable)) {
                    return self::CHANGED; // a property of the object, or the variable, written here
                }
            }

            if ($node instanceof Node\Stmt\Expression) {
                $dropped[spl_object_id($node->expr)] = true;
            } elseif ($node instanceof Node\Stmt\Return_ && $node->expr !== null) {
                $returned[spl_object_id($node->expr)] = $node->expr;
            }

            $read = $node instanceof Node\Expr\Instanceof_ ? $node->expr : ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch ? $node->var : null);
            if ($read !== null && self::names($read, $variable)) {
                unset($mentions[spl_object_id($read)]);
            }

            if ($node instanceof Node\Expr\CallLike && ! $node->isFirstClassCallable()) {
                $calls[] = $node; // a first-class callable may run later, anywhere: a mention in it stays unconsumed
            }
        }

        // What a closure or a class declared in the body returns is not what the body does.
        foreach ($finder->find($body, static fn (Node $node): bool => $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) as $nested) {
            foreach ($finder->findInstanceOf([$nested], Node\Stmt\Return_::class) as $return) {
                if ($return->expr !== null) {
                    unset($returned[spl_object_id($return->expr)]);
                }
            }
        }

        $handsBack = false;
        foreach ($returned as $expr) {
            if (self::names($expr, $variable)) {
                unset($mentions[spl_object_id($expr)]);
                $handsBack = true;
            }
        }

        foreach ($calls as $call) {
            $followed = [];
            if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) && self::names($call->var, $variable)) {
                foreach ($this->receivedBy($call, $held) ?? [] as $callee) {
                    $followed[] = [$callee, 'this'];
                    unset($mentions[spl_object_id($call->var)]);
                }
            } elseif ($variable === 'this' && $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && in_array($call->class->toLowerString(), ['self', 'static', 'parent'], true)) {
                // `self::m()` naming an instance method hands it `$this` without writing it.
                foreach ($this->rawCallees($call, $declaring, $held, $variable) ?? [] as $callee) {
                    if (! $callee->isStatic()) {
                        $followed[] = [$callee, 'this'];
                    }
                }
            }

            foreach ($call->getArgs() as $arg) {
                $root = self::root($arg->value);
                if ($arg->value === $root && self::names($root, $variable) && ! $arg->unpack) {
                    foreach ($this->rawCallees($call, $declaring, $held, $variable) ?? [] as $callee) {
                        $parameter = self::handedTo($callee, $call, $arg);
                        if ($parameter === null) {
                            return self::CHANGED;
                        }
                        $followed[] = [$callee, $parameter];
                        unset($mentions[spl_object_id($arg->value)]);
                    }
                } elseif ($arg->value !== $root && self::names($root, $variable) && $this->mayWriteThrough($call, $arg, $declaring, $held, $variable)) {
                    return self::CHANGED; // a property handed to a by-reference parameter, or to a callee this cannot name
                }
            }

            foreach ($followed as [$callee, $parameter]) {
                $kept = $this->keeps($callee, $parameter, $held, $depth + 1);
                if ($kept === self::HANDED_BACK && isset($returned[spl_object_id($call)])) {
                    $handsBack = true;
                } elseif ($kept === self::CHANGED || ($kept === self::HANDED_BACK && ! isset($dropped[spl_object_id($call)]))) {
                    return self::CHANGED; // changed, or handed back to be kept where this does not follow
                }
            }
        }

        // Assigned, captured, compared, or handed to a callee this cannot name: kept somewhere this does not
        // follow.
        if ($mentions !== []) {
            return self::CHANGED;
        }

        return $handsBack ? self::HANDED_BACK : self::KEPT;
    }

    /**
     * The method a call on the object runs, resolved on the class it holds.
     *
     * @return list<ReflectionMethod>|null
     */
    private function receivedBy(Node\Expr\CallLike $call, string $held): ?array
    {
        return $call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall ? $this->methods($call, [$held]) : null;
    }

    /**
     * The methods a call written in analysed code runs, by the types its scope gives.
     *
     * @return list<ReflectionMethod>|null
     */
    private function scopedCallees(Node\Expr\CallLike $call, Scope $scope): ?array
    {
        if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name) {
            $classes = [$scope->resolveName($call->class)];
        } elseif ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) {
            $classes = $scope->getType($call->var)->getObjectClassNames();
        } else {
            return null;
        }

        return $this->methods($call, $classes);
    }

    /**
     * The methods a call written in a body read from source runs, where its receiver names its class:
     * `$this` (the object followed, or the body's own), or `self::`, `static::`, `parent::` and a class name.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return list<ReflectionMethod>|null
     */
    private function rawCallees(Node\Expr\CallLike $call, ReflectionClass $declaring, string $held, string $variable): ?array
    {
        $own = $variable === 'this' ? $held : $declaring->getName();
        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) && self::names($call->var, 'this')) {
            return $this->methods($call, [$own]);
        }

        if (! $call instanceof Node\Expr\StaticCall || ! $call->class instanceof Node\Name) {
            return null;
        }

        $class = match ($call->class->toLowerString()) {
            'self' => $declaring->getName(),
            'static' => $own,
            'parent' => $declaring->getParentClass() === false ? null : $declaring->getParentClass()->getName(),
            default => $call->class->toString(),
        };

        return $class === null ? null : $this->methods($call, [$class]);
    }

    /**
     * @param  list<string>  $classes
     * @return list<ReflectionMethod>|null
     */
    private function methods(Node\Expr\CallLike $call, array $classes): ?array
    {
        $name = $call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall || $call instanceof Node\Expr\StaticCall ? $call->name : null;
        if (! $name instanceof Node\Identifier) {
            return null;
        }

        $methods = [];
        foreach ($classes as $class) {
            $method = $this->method($class, $name->toString());
            if ($method === null) {
                return null;
            }
            $methods[] = $method;
        }

        return $methods;
    }

    private function method(string $class, string $name): ?ReflectionMethod
    {
        if (! class_exists($class) && ! interface_exists($class)) {
            return null;
        }

        // Which declaration answers the name is the hierarchy's to say.
        array_push($this->read, ...DeclarationFiles::of($class));

        try {
            return (new ReflectionClass($class))->getMethod($name);
        } catch (Throwable) {
            return null; // a method only `__call` answers
        }
    }

    /**
     * The name the callee reads an argument by. A variadic one holds it in an array, and a by-reference one can
     * rebind it, both of which the callee's body then shows as a mention that is no read.
     */
    private static function handedTo(ReflectionFunctionAbstract $callee, Node\Expr\CallLike $call, Node\Arg $arg): ?string
    {
        return self::parameter($callee, $call, $arg)?->getName();
    }

    /**
     * Whether a callee may write through the argument: a by-reference parameter, or a callee this cannot name.
     *
     * @param  ReflectionClass<object>  $declaring
     */
    private function mayWriteThrough(Node\Expr\CallLike $call, Node\Arg $arg, ReflectionClass $declaring, string $held, string $variable): bool
    {
        $callees = $call instanceof Node\Expr\FuncCall ? self::function($call) : $this->rawCallees($call, $declaring, $held, $variable);
        if ($callees === null || $callees === []) {
            return true;
        }

        foreach ($callees as $callee) {
            if (self::parameter($callee, $call, $arg)?->isPassedByReference() === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * A function a call names, read where the file's namespace declares it, else globally.
     *
     * @return list<ReflectionFunction>|null
     */
    private static function function(Node\Expr\FuncCall $call): ?array
    {
        if (! $call->name instanceof Node\Name) {
            return null;
        }

        $namespaced = $call->name->getAttribute('namespacedName');
        foreach ([$namespaced instanceof Node\Name ? $namespaced->toString() : null, $call->name->toString()] as $name) {
            if ($name !== null && function_exists($name)) {
                return [new ReflectionFunction($name)];
            }
        }

        return null;
    }

    /** The parameter an argument binds to; none past a spread, or for an argument no parameter takes. */
    private static function parameter(ReflectionFunctionAbstract $callee, Node\Expr\CallLike $call, Node\Arg $arg): ?ReflectionParameter
    {
        $parameters = $callee->getParameters();
        if ($arg->name instanceof Node\Identifier) {
            foreach ($parameters as $parameter) {
                if ($parameter->getName() === $arg->name->toString()) {
                    return $parameter;
                }
            }

            return null;
        }

        foreach ($call->getArgs() as $position => $written) {
            if ($written->unpack) {
                return null;
            }
            if ($written === $arg) {
                $last = $parameters === [] ? null : $parameters[count($parameters) - 1];

                return $parameters[$position] ?? ($last !== null && $last->isVariadic() ? $last : null);
            }
        }

        return null;
    }

    /**
     * What a node writes: an assignment's target (each element of a destructuring one), what a reference is
     * taken of, an increment's, an unset's, and a foreach's loop variables or the array it iterates by reference.
     *
     * @return list<Node\Expr>
     */
    private static function writeTargets(Node $node): array
    {
        $targets = match (true) {
            $node instanceof Node\Expr\AssignRef => [$node->var, $node->expr],
            $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp,
            $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PostInc,
            $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostDec => [$node->var],
            $node instanceof Node\Stmt\Unset_ => $node->vars,
            $node instanceof Node\Stmt\Foreach_ => array_values(array_filter([$node->keyVar, $node->valueVar, $node->byRef ? $node->expr : null])),
            default => [],
        };

        $written = [];
        while ($targets !== []) {
            $target = array_shift($targets);
            if ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
                foreach ($target->items as $item) {
                    if ($item !== null) {
                        $targets[] = $item->value;
                    }
                }

                continue;
            }
            $written[] = $target;
        }

        return $written;
    }

    /** The variable a property or offset chain is read off, or the expression itself. */
    private static function root(Node\Expr $expr): Node\Expr
    {
        while ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch || $expr instanceof Node\Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }

        return $expr;
    }

    private static function names(Node $node, string $variable): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === $variable;
    }
}
