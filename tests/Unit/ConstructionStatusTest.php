<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\ConstructionStatus;
use Docuccino\Inference\PhpStan\Throwing\HttpStatusCode;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The ONE rule for "what status does this call pass in slot N", which a `throw new X(…)` and the
 * `new self(…)` one hop inside a factory both ask. They were two readers with two rules, and the pair
 * disagreed on the two rows marked below; the rows state the answer from PHP's own argument binding rather
 * than from either reader.
 */
function constructionCall(string $code): Node\Expr\CallLike
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code) ?? [];
    $call = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Expr\CallLike::class);

    expect($call)->not->toBeNull();

    /** @var Node\Expr\CallLike $call */
    return $call;
}

it('reads the status one call passes in a slot', function (string $code, int $slot, array $names, ?int $default, ?int $expected): void {
    $status = ConstructionStatus::inSlot(
        constructionCall($code),
        $slot,
        ['names' => $names, 'default' => $default],
        // A stand-in for the two real folds, which differ only in where they get the constant from.
        static fn (Node\Expr $argument): ?int => $argument instanceof Node\Scalar\Int_ ? $argument->value : null,
    );

    expect($status)->toBe($expected);
})->with([
    'positional' => ['new X(409);', 0, ['statusCode'], 422, 409],
    'a later slot' => ['new X([], 409);', 1, ['fields', 'statusCode'], 422, 409],
    // The first spelling the two readers disagreed on: PHP passes the default here, so the call really is
    // a 422 and reading it as "no status stated" left the throw site publishing nothing.
    'a slot the call left empty, which PHP fills with the default' => ['new X([]);', 1, ['fields', 'statusCode'], 422, 422],
    'a slot the call left empty with no default to fill it' => ['new X([]);', 1, ['fields', 'statusCode'], null, null],
    // The second: named, and the callee's parameters are what put it in the position a counting reader
    // would miss entirely.
    'named, with the signature known' => ['new X(message: \'no\', statusCode: 423);', 0, ['statusCode', 'message'], null, 423],
    'named, with no signature to place it' => ['new X(message: \'no\', statusCode: 423);', 0, [], null, null],
    // A spread written out as a plain list IS its arguments; any other spread makes the position opaque,
    // and reading its absence as "the default was taken" would publish a status the call never passed.
    'a spread written as a list' => ['new X(...[[], 409]);', 1, ['fields', 'statusCode'], 422, 409],
    'a spread of something unreadable' => ['new X(...$args);', 1, ['fields', 'statusCode'], 422, null],
    // A callable's arguments are supplied somewhere this read cannot see.
    'a first-class callable' => ['$make = new X(...);', 0, ['statusCode'], 422, null],
    'an argument nothing folds' => ['new X($runtime);', 0, ['statusCode'], 422, null],
    // Out of range from either source, which would become a response key no consumer can read.
    'an out-of-range argument' => ['new X(0);', 0, ['statusCode'], null, null],
    'an out-of-range default' => ['new X();', 0, ['statusCode'], 0, null],
    // The registry's spelling: no signature and no default, on a call PHPStan has already normalised.
    'a plain function call with no signature in play' => ['abort(404);', 0, [], null, 404],
]);

it('admits exactly the integers a response can be keyed by', function (?int $value, ?int $expected): void {
    // Stated from the format rather than from the code: OpenAPI keys a response by `1xx`–`5xx`, so anything
    // else is a key no consumer can read — and IANA's assigned set is NOT the rule, because an application
    // really does send its own 599.
    expect(HttpStatusCode::folded($value))->toBe($expected);
})->with([
    'nothing folded' => [null, null],
    'the lowest a response can be keyed by' => [100, 100],
    'one below it' => [99, null],
    'the highest' => [599, 599],
    'one above it' => [600, null],
    'the key a bare zero would mint' => [0, null],
    'a negative, as `abort(-1)` folds to' => [-1, null],
    'an ordinary status' => [422, 422],
    'a status nobody has assigned, which the server may still send' => [512, 512],
]);
