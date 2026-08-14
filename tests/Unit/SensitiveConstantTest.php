<?php

declare(strict_types=1);

use Docuccino\Core\Lint\SensitiveFieldLintOptions;
use Docuccino\Inference\PhpStan\Analysis\SensitiveConstant;
use PhpParser\Node;

/** `Klass::NAME`. */
function scClassConst(string $name): Node\Expr\ClassConstFetch
{
    return new Node\Expr\ClassConstFetch(new Node\Name('Klass'), new Node\Identifier($name));
}

it('names every sensitive-constant heuristic core knows, so the tables cannot drift apart', function (string $token, string $label): void {
    // The constant is spelled from the token itself: whatever core adds to the table is suppressed here
    // too, with no second list to maintain.
    expect(SensitiveConstant::label(scClassConst(strtoupper($token))))->toBe($label);
})->with(array_map(
    static fn (string $token, string $label): array => [$token, $label],
    array_keys(SensitiveFieldLintOptions::DEFAULT_PATTERNS),
    array_values(SensitiveFieldLintOptions::DEFAULT_PATTERNS),
));

it('reads the sensitive name through the shapes a constant can take', function (Node\Expr $expr, ?string $label): void {
    expect(SensitiveConstant::label($expr))->toBe($label);
})->with([
    'class constant' => [scClassConst('SIGNING_SECRET'), 'a secret'],
    'global constant' => [new Node\Expr\ConstFetch(new Node\Name('APP_API_KEY')), 'an API key'],
    'namespaced global constant' => [new Node\Expr\ConstFetch(new Node\Name(['Vendor', 'CLIENT_SECRET'])), 'a client secret'],
    'concatenation, left' => [new Node\Expr\BinaryOp\Concat(scClassConst('API_KEY'), new Node\Scalar\String_('-suffix')), 'an API key'],
    'concatenation, right' => [new Node\Expr\BinaryOp\Concat(new Node\Scalar\String_('prefix-'), scClassConst('API_KEY')), 'an API key'],
    'innocuous class constant' => [scClassConst('DEFAULT_TITLE'), null],
    'true' => [new Node\Expr\ConstFetch(new Node\Name('true')), null],
    'dynamic class constant' => [new Node\Expr\ClassConstFetch(new Node\Name('Klass'), new Node\Expr\Variable('name')), null],
    'plain string literal' => [new Node\Scalar\String_('about:blank'), null],
    'variable' => [new Node\Expr\Variable('detail'), null],
]);
