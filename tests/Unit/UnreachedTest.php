<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\Unreached;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;

/**
 * Which operands PHP always evaluates before the call, construction or `throw` they belong to: arguments,
 * and the receiver of a method call. A `throw` among them means the outer node never completes. An
 * operand PHP may skip — the right of `??`, a ternary's branch, a nullsafe call's arguments — proves
 * nothing, since the request can take the path that skips it. Whether a lookup always throws is
 * {@see EnumLookupTest}'s; no method resolves here, so no lookup does.
 */
function unreachedExpression(string $code): Node\Expr
{
    $stmts = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';');
    $stmt = $stmts[0] ?? null;

    return $stmt instanceof Node\Stmt\Expression ? $stmt->expr : throw new RuntimeException('not an expression: '.$code);
}

it('reads an operand that always throws as making its node unreachable', function (string $code, bool $expected): void {
    $scope = $this->createStub(Scope::class);
    $scope->method('getMethodReflection')->willReturn(null);

    expect(Unreached::byOperand(unreachedExpression($code), $scope, static fn (string $enum) => null))->toBe($expected);
})->with([
    'a construction handed a throw' => ['new Refusal(throw new LogicException)', true],
    'a function call handed one' => ['abort(throw new LogicException)', true],
    'a static call handed one, named' => ['Refusal::make(status: throw new LogicException)', true],
    'a throw of a construction handed one' => ['throw new Refusal(throw new LogicException)', true],
    'a construction one argument in' => ['new Refusal(new Reason(throw new LogicException))', true],
    'a property read off one' => ['abort((throw new LogicException)->value)', true],
    'a method called on one' => ['(throw new LogicException)->send()', true],
    'a nullsafe call on one' => ['(throw new LogicException)?->send()', true],
    // Operands PHP may skip.
    'the right of ??' => ['new Refusal($reason ?? throw new LogicException)', false],
    'a ternary branch' => ['abort($locked ? 423 : throw new LogicException)', false],
    'a nullsafe call\'s argument' => ['$export?->refuse(throw new LogicException)', false],
    // Nothing that throws, and nothing to call.
    'a plain construction' => ['new Refusal(423)', false],
    'a first-class callable' => ['abort(...)', false],
    'a throw of a variable' => ['throw $refusal', false],
    'a throw of a construction, no operand throwing' => ['throw new Refusal(423)', false],
    // The point that throws is reached: a bare `throw` is the throw, not something ahead of it.
    'not a call' => ['$refusal', false],
]);

it('reads a value that always throws before it is returned', function (string $code, bool $expected): void {
    $scope = $this->createStub(Scope::class);
    $scope->method('getMethodReflection')->willReturn(null);

    expect(Unreached::alwaysThrows(unreachedExpression($code), $scope, static fn (string $enum) => null))->toBe($expected);
})->with([
    'a throw' => ['throw new LogicException', true],
    'a response handed one as its status' => ['response()->json([], throw new LogicException)', true],
    'a response handed a fetch off one' => ['response()->json([], (throw new LogicException)?->value)', true],
    'a response with a status' => ['response()->json([], 423)', false],
    'a variable' => ['$response', false],
]);
