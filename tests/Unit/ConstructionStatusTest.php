<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\ConstructionSite;
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

/**
 * Every `new X(...)` in a snippet as the site it is written at — which is what the class read and the
 * factory read each hand the fold. One file per snippet here; that a class's constructions can come from
 * SEVERAL is {@see HttpExceptionStatusTest}'s hierarchy rows.
 *
 * @return list<ConstructionSite>
 */
function constructionSet(string $code, string $file = '/x.php'): array
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code) ?? [];

    /** @var list<Node\Expr\New_> $found */
    $found = (new NodeFinder)->findInstanceOf($parsed, Node\Expr\New_::class);

    return array_map(
        static fn (Node\Expr\New_ $new): ConstructionSite => new ConstructionSite($new, $file, 'App\\X'),
        $found,
    );
}

it('reads the one status a set of constructions agrees on', function (string $code, ?int $expected): void {
    // The rule a class and a factory both answer by: a set of constructions states a status only where
    // every one of them states the same one. Written from what the code does rather than from either
    // reader — two `new`s at two statuses really are two responses, and one nobody can read leaves the
    // rest unable to speak for it.
    $status = ConstructionStatus::agreedIn(
        constructionSet($code),
        1,
        ['names' => ['fields', 'statusCode'], 'default' => 422],
        static fn (Node\Expr $argument): ?int => $argument instanceof Node\Scalar\Int_ ? $argument->value : null,
    );

    expect($status)->toBe($expected);
})->with([
    'one construction' => ['new X([], 409);', 409],
    'two agreeing' => ['new X([], 409); new X([\'a\'], 409);', 409],
    // The row the class read used to decline outright: one construction takes the default and the other
    // writes the same value, so the class really does have one status.
    'one taking the default and one writing it' => ['new X([]); new X([], 422);', 422],
    'two disagreeing' => ['new X([], 409); new X([], 403);', null],
    // One nobody can read takes the whole answer with it: the others cannot speak for a construction
    // whose status may be anything.
    'one that folds and one that does not' => ['new X([], 409); new X([], $chosen);', null],
    'one behind an unreadable spread' => ['new X([], 409); new X(...$args);', null],
    'one as a first-class callable' => ['new X([], 409); $make = new X(...);', null],
    // Nothing to agree on is not agreement: a class that builds itself nowhere states nothing here, and
    // whether its constructor's default may speak instead is the caller's question, not this one's.
    'no constructions at all' => ['return $this->fields;', null],
    'agreeing on a number that is no status' => ['new X([], 0); new X([], 0);', null],
]);

it('folds each construction in the file it was written in', function (): void {
    // A class's constructions are not all in one file — a base's `new static(…)` builds the subclass from
    // the base's own file — so the fold is told WHERE each one is written. Stated as the rule rather than
    // read off the code: an argument folded against another file's scope is a value that line never passed.
    $seen = [];
    $status = ConstructionStatus::agreedIn(
        [...constructionSet('new X([], 409);', '/own.php'), ...constructionSet('new X([], 409);', '/base.php')],
        1,
        ['names' => ['fields', 'statusCode'], 'default' => null],
        static function (Node\Expr $argument, ConstructionSite $site) use (&$seen): ?int {
            $seen[] = $site->file;

            return $argument instanceof Node\Scalar\Int_ ? $argument->value : null;
        },
    );

    expect($status)->toBe(409)
        ->and($seen)->toBe(['/own.php', '/base.php']);
});

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
