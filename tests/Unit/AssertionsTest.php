<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\Assertions;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;

/**
 * A throw is an assertion's where it is reached only past a failed type test — `instanceof`, a type function
 * such as `is_string()`, a test for `null` — on every way the guards on the path can fail. A test of what a
 * value HOLDS is a request's to fail, and makes the throw reachable. Each row states its answer from that
 * rule, not from the reader: the guard's form, `if`, `??`, `||`, an early return, is never what decides.
 */
function assertedAt(string $body): bool
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php function f($v, $w, $flag) { '.$body.' }') ?? [];
    $function = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Stmt\Function_::class);
    expect($function)->toBeInstanceOf(Node\Stmt\Function_::class);

    // The throw asked about builds `Target`; any other is scenery.
    $target = (new NodeFinder)->findFirst($parsed, static fn (Node $node): bool => $node instanceof Node\Expr\Throw_
        && $node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name && $node->expr->class->toString() === 'Target');
    expect($target)->toBeInstanceOf(Node\Expr\Throw_::class);

    /** @var Node\Stmt\Function_ $function */
    /** @var Node\Expr\Throw_ $target */
    return Assertions::behindIn(array_values($function->stmts), $target);
}

/**
 * Every type function PHP spells, with what its returning TRUE says, stated here rather than read off the
 * reader: the value is the type the code goes on to use, or it is `null`.
 *
 * @return array<string, array{string, bool}>
 */
function typeTestRows(): array
{
    return [
        'is_array' => ['is_array', true],
        'is_bool' => ['is_bool', true],
        'is_callable' => ['is_callable', true],
        'is_countable' => ['is_countable', true],
        'is_double' => ['is_double', true],
        'is_float' => ['is_float', true],
        'is_int' => ['is_int', true],
        'is_integer' => ['is_integer', true],
        'is_iterable' => ['is_iterable', true],
        'is_long' => ['is_long', true],
        'is_null' => ['is_null', false],
        'is_object' => ['is_object', true],
        'is_resource' => ['is_resource', true],
        'is_scalar' => ['is_scalar', true],
        'is_string' => ['is_string', true],
    ];
}

it('lists every type function PHP spells, and only those', function (): void {
    // The rows above are the domain; the reader's table must be exactly it, so a function added to one and
    // not the other fails here rather than passing every row it is not in.
    $stated = array_map(static fn (array $row): bool => $row[1], typeTestRows());
    ksort($stated);
    $read = Assertions::TYPE_TESTS;
    ksort($read);

    expect($read)->toBe($stated);
});

it('reads each type function failing as a failed type test, and passing as none', function (string $function, bool $truePasses): void {
    // Fails where it returns what a value of the wrong type returns.
    $failing = $truePasses ? "if (! {$function}(\$v)) { throw new Target; }" : "if ({$function}(\$v)) { throw new Target; }";
    $passing = $truePasses ? "if ({$function}(\$v)) { throw new Target; }" : "if (! {$function}(\$v)) { throw new Target; }";

    expect(assertedAt($failing))->toBeTrue()
        ->and(assertedAt($passing))->toBeFalse();
})->with(typeTestRows());

it('reads no type test in a function that reads a value', function (string $guard): void {
    expect(assertedAt("if (! {$guard}) { throw new Target; }"))->toBeFalse();
})->with([
    // `is_numeric()` reads a string's content, which a request decides.
    'is_numeric()' => ['is_numeric($v)'],
    'an application\'s own check' => ['is_valid($v)'],
    'a method' => ['$v->isValid()'],
    'a comparison with a value' => ['$v > 3'],
    // Loose `==` also matches `0`, `''` and `[]`, so it reads the value.
    'a loose comparison with null' => ['$v != null'],
    'a flag' => ['$flag'],
]);

it('reads a throw reached past a failed type test, however the guard is written', function (string $body): void {
    expect(assertedAt($body))->toBeTrue();
})->with([
    'instanceof, negated' => ['if (! $v instanceof Model) { throw new Target; }'],
    '=== null' => ['if ($v === null) { throw new Target; }'],
    'null === on the left' => ['if (null === $v) { throw new Target; }'],
    '!== null, negated' => ['if (! ($v !== null)) { throw new Target; }'],
    'after a log line' => ['if (! $v instanceof Model) { log($v); throw new Target; }'],
    'nested in a test of a value' => ['if ($v === null) { if ($flag) { throw new Target; } }'],
    'in an elseif' => ['if ($flag) { return 1; } elseif (! $v instanceof Model) { throw new Target; }'],
    // Reaching the `else` passed every condition before it false.
    'in an else' => ['if ($v instanceof Model) { return 1; } else { throw new Target; }'],
    'in an else past an elseif' => ['if ($v instanceof Model) { return 1; } elseif ($flag) { return 2; } else { throw new Target; }'],
    'after an if that returns' => ['if ($v instanceof Model) { return 1; } throw new Target;'],
    '??' => ['return $v ?? throw new Target;'],
    '?? chained' => ['return $v ?? $w ?? throw new Target;'],
    '??=' => ['$v ??= throw new Target; return $v;'],
    '||' => ['$v instanceof Model || throw new Target;'],
    'or' => ['$v instanceof Model or throw new Target;'],
    '&&' => ['$v === null && throw new Target;'],
    'a ternary\'s else' => ['return $v instanceof Model ? $v : throw new Target;'],
    'a ternary\'s if' => ['return is_null($v) ? throw new Target : $v;'],
    // The right of `&&` in an if's condition is reached only where its left held.
    'in an if\'s condition, past its test' => ['if ($v === null && check(throw new Target)) { return 1; }'],
    // Every way a conjunction can fail is a type test failing.
    'two type tests, both required' => ['if (! ($v instanceof Model && is_string($w))) { throw new Target; }'],
    'either of two nulls' => ['if ($v === null || $w === null) { throw new Target; }'],
    // Both hold, and one of them is a type test failing.
    'a null and a flag' => ['if ($v === null && $flag) { throw new Target; }'],
    // Neither holds, and one of them is a type test failing.
    'a type test or a flag, neither' => ['if (! ($v instanceof Model || $flag)) { throw new Target; }'],
]);

it('reads a throw a request can reach past a test of a value', function (string $body): void {
    expect(assertedAt($body))->toBeFalse();
})->with([
    'no guard' => ['throw new Target;'],
    'a flag' => ['if ($flag) { throw new Target; }'],
    // Throwing where the value IS of the type is dispatch, not a failed narrowing.
    'instanceof, held' => ['if ($v instanceof Model) { throw new Target; }'],
    '!== null, held' => ['if ($v !== null) { throw new Target; }'],
    // One way the guard fails reads the value.
    'a type test joined to a test of the value' => ['if (! (is_string($v) && strlen($v) === 36)) { throw new Target; }'],
    'a type test or a flag, either' => ['if ($v === null || $flag) { throw new Target; }'],
    // `?:` tests what the value holds, `0` and `''` among it.
    'a short ternary' => ['return $v ?: throw new Target;'],
    'the left of ??' => ['return (throw new Target) ?? $v;'],
    'after an if that may not return' => ['if ($v instanceof Model) { log($v); } throw new Target;'],
    // Evaluated before the test it sits beside, so nothing has been tested yet.
    'in an if\'s condition, ahead of its test' => ['if (check(throw new Target) && $v === null) { return 1; }'],
    // A nested function is its own path: whatever calls it is what guards it.
    'in a closure written behind the test' => ['if ($v === null) { $f = function () { throw new Target; }; }'],
]);

/**
 * `throw_if()` and `throw_unless()` read as the `if` they stand for.
 */
function handedAsserted(string $code, string $function): bool
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';') ?? [];
    $call = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Expr\FuncCall::class);
    expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class);

    $parameters = [];
    foreach (['condition', 'exception'] as $name) {
        $parameter = test()->createStub(ParameterReflection::class);
        $parameter->method('getName')->willReturn($name);
        $parameters[] = $parameter;
    }
    $variant = test()->createStub(ExtendedParametersAcceptor::class);
    $variant->method('getParameters')->willReturn($parameters);
    $reflection = test()->createStub(FunctionReflection::class);
    $reflection->method('getName')->willReturn($function);
    $reflection->method('getVariants')->willReturn([$variant]);

    /** @var Node\Expr\FuncCall $call */
    return Assertions::handed($reflection, $call);
}

it('reads the condition a throwing helper is handed as the guard it is', function (string $code, string $function, bool $asserted): void {
    expect(handedAsserted($code, $function))->toBe($asserted);
})->with([
    'throw_unless() on instanceof' => ['throw_unless($v instanceof Model, $e)', 'throw_unless', true],
    'throw_if() on === null' => ['throw_if($v === null, $e)', 'throw_if', true],
    'throw_if() on a negated type function' => ['throw_if(! is_string($v), $e)', 'throw_if', true],
    'the condition by name' => ['throw_unless(exception: $e, condition: $v instanceof Model)', 'throw_unless', true],
    'throw_if() on instanceof' => ['throw_if($v instanceof Model, $e)', 'throw_if', false],
    'throw_unless() on a flag' => ['throw_unless($flag, $e)', 'throw_unless', false],
    'throw_unless() on a type test and a value' => ['throw_unless(is_string($v) && Str::isUuid($v), $e)', 'throw_unless', false],
    // Any other function has no condition to read.
    'a thrower with no condition' => ['abort($v instanceof Model)', 'abort', false],
    'no condition written' => ['throw_unless()', 'throw_unless', false],
]);
