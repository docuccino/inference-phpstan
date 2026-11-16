<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use Docuccino\Inference\PhpStan\Throwing\StatusForwarding;
use Docuccino\Inference\PhpStan\Throwing\SubclassStatus;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * What a subclass's constructors hand its root's constructor besides a status — a framework response's body
 * (the first argument of each that takes one) and headers, and the response an `HttpResponseException`
 * carries — read by the same statements grammar as the status ({@see SubclassStatus}): the one
 * `parent::__construct()` each constructor makes, walked up to the root. The answer is either the expression
 * that lands in the slot, with the file and the call it is written in (where its scope is), or the parameter
 * of the class's own constructor handed through untouched, for a construction to fill. Anything else, or a
 * constructor that rewrites the slot's value once its parent's has run, answers null: nothing read, never
 * "empty".
 *
 * Nothing here resolves a type: the bodies come from {@see ClassBodies}, and the caller types what lands.
 *
 * @phpstan-type Landing array{expr: Node\Expr, file: string, call: Node\Expr\StaticCall}|array{parameter: int}
 *
 * @internal
 */
final class ParentArgument
{
    private const BODY = 'body';

    private const HEADERS = 'headers';

    private const RESPONSE = 'response';

    /** What a constructor may call once its parent's has run to replace each slot's value. */
    private const REWRITES = [
        self::BODY => ['setContent', 'setData', 'setJson'],
        self::HEADERS => ['header', 'withHeaders'],
        self::RESPONSE => [],
    ];

    /** The slots a root keeps in a property of the same name, which a constructor may also write itself. */
    private const HELD = [self::HEADERS, self::RESPONSE];

    /** @var array<string, Landing|null> by slot, class and root */
    private array $cache = [];

    public function __construct(
        private readonly ClassBodies $bodies,
        private readonly ProjectFilter $appFilter,
    ) {}

    /** @return Landing|null */
    public function body(string $fqcn, string $root): ?array
    {
        return $this->of(self::BODY, $fqcn, $root);
    }

    /** @return Landing|null */
    public function headers(string $fqcn, string $root): ?array
    {
        return $this->of(self::HEADERS, $fqcn, $root);
    }

    /** @return Landing|null */
    public function response(string $fqcn, string $root): ?array
    {
        return $this->of(self::RESPONSE, $fqcn, $root);
    }

    /**
     * @param  self::BODY|self::HEADERS|self::RESPONSE  $slot
     * @return Landing|null
     */
    private function of(string $slot, string $fqcn, string $root): ?array
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        $key = $slot.'|'.$fqcn.'|'.$root;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        // Seeded first, so a hierarchy that cannot terminate answers rather than recurses.
        $this->cache[$key] = null;

        return $this->cache[$key] = $this->read($slot, new ReflectionClass($fqcn), $root);
    }

    /**
     * @param  self::BODY|self::HEADERS|self::RESPONSE  $slot
     * @param  ReflectionClass<object>  $class
     * @return Landing|null
     */
    private function read(string $slot, ReflectionClass $class, string $root): ?array
    {
        if ($class->getName() === $root) {
            $index = $slot === self::BODY ? 0 : array_search($slot, self::names($class->getConstructor()), true);

            return is_int($index) ? ['parameter' => $index] : null;
        }

        $parent = $class->getParentClass();
        if ($parent === false) {
            return null;
        }

        $inherited = $this->of($slot, $parent->getName(), $root);
        $constructor = $class->getConstructor();

        // No constructor of its own, so the parent's runs and its answer is this class's.
        if ($inherited === null || $constructor === null || $constructor->getDeclaringClass()->getName() !== $class->getName()) {
            return $inherited;
        }

        $file = $class->getFileName();
        $body = $file === false || ! $this->appFilter->isProjectFile($file)
            ? null
            : ($this->bodies->methods($file, $class->getName())['__construct'] ?? null);
        $call = $body === null ? null : StatusForwarding::parentCall($body);
        if ($file === false || $body === null || $call === null || $this->rewrites($slot, $body)) {
            return null;
        }

        // A value the parent fixes is one no subclass can move.
        if (isset($inherited['expr'])) {
            return $inherited;
        }

        $argument = ArgumentSlots::of($call->getArgs(), self::names($parent->getConstructor()))->at($inherited['parameter']);
        if ($argument === null) {
            return null;
        }

        if ($argument instanceof Node\Expr\Variable && is_string($argument->name) && ! StatusForwarding::reassigns($body, $argument->name)) {
            $index = array_search($argument->name, self::names($constructor), true);
            if (is_int($index)) {
                return ['parameter' => $index];
            }
        }

        return ['expr' => $argument, 'file' => $file, 'call' => $call];
    }

    /**
     * Whether the constructor rewrites the slot's value itself — a held slot also through the property it is
     * kept in (`$this->headers->set(…)`, `$this->response = …`).
     *
     * @param  self::BODY|self::HEADERS|self::RESPONSE  $slot
     * @param  array<array-key, Node\Stmt>  $body
     */
    private function rewrites(string $slot, array $body): bool
    {
        foreach (self::REWRITES[$slot] as $method) {
            if (StatusForwarding::selfCalls($body, $method) !== []) {
                return true;
            }
        }

        return in_array($slot, self::HELD, true) && (new NodeFinder)->findFirst(
            $body,
            static fn (Node $node): bool => $node instanceof Node\Expr\PropertyFetch
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === $slot,
        ) !== null;
    }

    /** @return list<string> */
    private static function names(?ReflectionMethod $method): array
    {
        return $method === null ? [] : array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        );
    }
}
