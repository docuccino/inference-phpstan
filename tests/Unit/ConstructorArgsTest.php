<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\ConstructorArgs;
use PhpParser\Node;

/**
 * Naming a `new`'s arguments is what makes "which members did this call site supply?" answerable, so the two
 * ways of writing the same call have to name the same members — and a spread, which names nothing, must not
 * be guessed at.
 */
function caNew(Node\Arg ...$args): Node\Expr\New_
{
    return new Node\Expr\New_(new Node\Name('App\\Data\\ProblemData'), $args);
}

/** A positional argument carrying a string literal. */
function caPositional(string $value): Node\Arg
{
    return new Node\Arg(new Node\Scalar\String_($value));
}

/** A named argument carrying a string literal. */
function caNamed(string $name, string $value): Node\Arg
{
    return new Node\Arg(new Node\Scalar\String_($value), name: new Node\Identifier($name));
}

/** @return array<string, string> argument name → the literal it carries */
function caValues(Node\Expr\New_ $new, array $paramNames): array
{
    $values = [];
    foreach (ConstructorArgs::named($new, $paramNames) as $name => $expr) {
        $values[$name] = $expr instanceof Node\Scalar\String_ ? $expr->value : '?';
    }

    return $values;
}

it('names positional and named arguments the same way', function (): void {
    $params = ['type', 'title', 'status'];

    $positional = caValues(caNew(caPositional('about:blank'), caPositional('Gone')), $params);
    $named = caValues(caNew(caNamed('title', 'Gone'), caNamed('type', 'about:blank')), $params);

    // Same members, whichever way the call was written; `status` was supplied by neither.
    expect($positional)->toBe(['type' => 'about:blank', 'title' => 'Gone'])
        ->and($named)->toBe(['title' => 'Gone', 'type' => 'about:blank'])
        ->and(array_keys($named))->not->toContain('status');
});

it('counts positions past a named argument, so a mixed call still lands right', function (): void {
    // PHP allows `new Foo($type, status: 500, title: 'x')`; the positional counter must ignore the named
    // ones rather than treating them as filling a slot.
    $new = caNew(caPositional('about:blank'), caNamed('status', '500'), caPositional('Gone'));

    expect(caValues($new, ['type', 'title', 'status']))
        ->toBe(['type' => 'about:blank', 'status' => '500', 'title' => 'Gone']);
});

it('stops at a spread rather than mis-attributing what follows', function (): void {
    $spread = new Node\Arg(new Node\Expr\Variable('args'), unpack: true);
    $new = caNew(caPositional('about:blank'), $spread, caPositional('Gone'));

    expect(caValues($new, ['type', 'title']))->toBe(['type' => 'about:blank']);
});

it('names nothing when the constructor’s parameters are unknown', function (): void {
    // No reflection for the class: a positional argument can't be named, and guessing one would attribute a
    // value to the wrong member. Explicitly named arguments still come through.
    expect(caValues(caNew(caPositional('about:blank')), []))->toBe([])
        ->and(caValues(caNew(caNamed('type', 'about:blank')), []))->toBe(['type' => 'about:blank']);
});
