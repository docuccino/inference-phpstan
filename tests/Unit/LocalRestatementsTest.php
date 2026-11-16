<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Analysis\LocalRestatements;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/*
 * What the statements ahead of a returned local restate about the response it holds. The server sends the
 * response as the last statement to run left it, so a status set on a line of its own replaces the one the
 * response was built with, a status set on only some paths is one more the response can be sent with, and
 * anything that may have changed the object in a way nobody read leaves nothing of what it was built with
 * standing. The chain reader and the keeping question are stood in for here: what each says is the real
 * engine's to prove ({@see StatementStatusTest}); the folding and where a statement runs are this class's.
 */

/**
 * The restatements of `$response` ahead of where the last `$returned` in a function body written as `$code`
 * names it, with a chain reader that knows `setStatusCode(<int>)`, `setStatusCode(<anything else>)` as unread,
 * `header(<name>, <string>)`, `setData(<array>)` as an empty object and `setContent()` as an unread body, and
 * declines `fresh()`; and a keeping question answering yes for `getStatusCode()`, `duplicate()` and the response
 * handed to `logged()`.
 *
 * @return array<string, mixed>|null
 */
function localRestatementsOf(string $code, string $returned = 'return $response;'): ?array
{
    $source = "<?php function handle() {\n".$code."\n}";
    $function = (new ParserFactory)->createForNewestSupportedVersion()->parse($source)[0] ?? null;
    assert($function instanceof Node\Stmt\Function_);

    $kept = [];
    foreach ((new NodeFinder)->findInstanceOf($function->stmts, Node\Expr\FuncCall::class) as $call) {
        foreach ($call->name instanceof Node\Name && $call->name->toString() === 'logged' ? $call->getArgs() : [] as $argument) {
            $kept[$argument->value->getStartFilePos()] = true;
        }
    }

    $peel = static function (Node\Expr\MethodCall $call): ?array {
        $chain = ['status' => null, 'statusUnknown' => false, 'contentType' => null, 'contentTypeUnknown' => false, 'body' => null, 'bodyUnknown' => false];
        $current = $call;
        while ($current instanceof Node\Expr\MethodCall) {
            $name = $current->name instanceof Node\Identifier ? $current->name->toString() : '';
            $arguments = array_map(static fn (Node\Arg $argument): Node\Expr => $argument->value, $current->getArgs());
            $link = match ($name) {
                'setStatusCode' => ($arguments[0] ?? null) instanceof Node\Scalar\Int_
                    ? ['status' => new LiteralT($arguments[0]->value)]
                    : ['statusUnknown' => true],
                'header' => ($arguments[0] ?? null) instanceof Node\Scalar\String_ && $arguments[0]->value === 'Content-Type' && ($arguments[1] ?? null) instanceof Node\Scalar\String_
                    ? ['contentType' => $arguments[1]->value]
                    : [],
                'setData' => ['body' => new ArrayShapeT([])],
                'setContent' => ['bodyUnknown' => true],
                'fresh' => false,
                default => null,
            };
            if ($link === null) {
                break;
            }
            if ($link === false) {
                return null;
            }
            if ($chain['status'] === null && ! $chain['statusUnknown']) {
                [$chain['status'], $chain['statusUnknown']] = [$link['status'] ?? null, $link['statusUnknown'] ?? false];
            }
            $chain['contentType'] ??= $link['contentType'] ?? null;
            if ($chain['body'] === null && ! $chain['bodyUnknown']) {
                [$chain['body'], $chain['bodyUnknown']] = [$link['body'] ?? null, $link['bodyUnknown'] ?? false];
            }
            $current = $current->var;
        }

        return $current === $call ? null : ['receiver' => $current] + $chain;
    };
    $keeps = static fn (int $at, ?Node\Expr\MethodCall $madeOn): bool => isset($kept[$at])
        || ($madeOn !== null && $madeOn->name instanceof Node\Identifier && in_array($madeOn->name->toString(), ['getStatusCode', 'duplicate'], true));

    return (new LocalRestatements($peel, $keeps))->before($function->stmts, 'response', strrpos($source, $returned) + strpos($returned, '$response'));
}

/**
 * @param  array<string, mixed>  $restated
 * @return array<string, mixed>
 */
function localRestatement(array $restated): array
{
    return $restated + ['status' => null, 'statusUnknown' => false, 'contentType' => null, 'contentTypeUnknown' => false, 'mayAlsoBe' => [], 'body' => null, 'bodyUnknown' => false];
}

it('reads a status set on a line of its own as the one the response is sent with', function (string $code, array $restated): void {
    expect(localRestatementsOf($code))->toEqual(localRestatement($restated));
})->with([
    'set once' => ['$response = make(); $response->setStatusCode(202); return $response;', ['status' => new LiteralT(202)]],
    // The last one to run is the one sent.
    'set twice' => ['$response = make(); $response->setStatusCode(201); $response->setStatusCode(202); return $response;', ['status' => new LiteralT(202)]],
    'set by a chain of its own, an outer link winning' => ['$response = make(); $response->setStatusCode(201)->setStatusCode(202); return $response;', ['status' => new LiteralT(202)]],
    'set with what it hands back kept' => ['$response = make(); $same = $response->setStatusCode(202); return $response;', ['status' => new LiteralT(202)]],
    'set ahead of a return inside a branch' => ['$response = make(); if (ready()) { $response->setStatusCode(202); return $response; } return other();', ['status' => new LiteralT(202)]],
    'set before the local is replaced, then set again' => ['$response = make(); $response->setStatusCode(500); $response = make(); $response->setStatusCode(202); return $response;', ['status' => new LiteralT(202)]],
    // A response restated on every path, then again on one: either can be sent.
    'set, then set again on one branch' => ['$response = make(); $response->setStatusCode(201); if (ready()) { $response->setStatusCode(202); } return $response;', ['status' => new LiteralT(201), 'mayAlsoBe' => [new LiteralT(202)]]],
    'set from something nobody can read' => ['$response = make(); $response->setStatusCode($code); return $response;', ['statusUnknown' => true]],
    // A status read at last replaces one that was not.
    'set unread, then set' => ['$response = make(); $response->setStatusCode($code); $response->setStatusCode(202); return $response;', ['status' => new LiteralT(202)]],
    'its media type set by a header' => ['$response = make(); $response->header(\'Content-Type\', \'text/csv\'); return $response;', ['contentType' => 'text/csv']],
    'a status and a media type set in one chain' => ['$response = make(); $response->setStatusCode(202)->header(\'Content-Type\', \'text/csv\'); return $response;', ['status' => new LiteralT(202), 'contentType' => 'text/csv']],
    // A body replaced on a line of its own is the body sent, and leaves the status as it was.
    'its body set' => ['$response = make(); $response->setData([]); return $response;', ['body' => new ArrayShapeT([])]],
    'its body set nobody can read' => ['$response = make(); $response->setContent($text); return $response;', ['bodyUnknown' => true]],
    'its body set unread, then set' => ['$response = make(); $response->setContent($text); $response->setData([]); return $response;', ['body' => new ArrayShapeT([])]],
]);

it('joins a status set on only some paths to the one before it', function (string $code, array $restated): void {
    // The statement may not have run, so the response is sent with either status.
    expect(localRestatementsOf($code))->toEqual(localRestatement($restated));
})->with([
    'on a branch' => ['$response = make(); if (ready()) { $response->setStatusCode(202); } return $response;', ['mayAlsoBe' => [new LiteralT(202)]]],
    'in a loop' => ['$response = make(); foreach (items() as $item) { $response->setStatusCode(207); } return $response;', ['mayAlsoBe' => [new LiteralT(207)]]],
    'in a try whose catch returns' => ['$response = make(); try { $response->setStatusCode(202); risky(); } catch (Exception $e) { return $response; } return other();', ['mayAlsoBe' => [new LiteralT(202)]]],
    'in a condition only some paths evaluate' => ['$response = make(); if (ready() && $response->setStatusCode(202)) { log(); } return $response;', ['mayAlsoBe' => [new LiteralT(202)]]],
    // The media type on one path is not the media type.
    'a media type on a branch' => ['$response = make(); if (ready()) { $response->header(\'Content-Type\', \'text/csv\'); } return $response;', ['contentTypeUnknown' => true]],
    'an unread status on a branch' => ['$response = make(); if (ready()) { $response->setStatusCode($code); } return $response;', ['statusUnknown' => true]],
    // Nor is the body on one path the body.
    'a body on a branch' => ['$response = make(); if (ready()) { $response->setData([]); } return $response;', ['bodyUnknown' => true]],
]);

it('leaves nothing of the response standing where something nobody read may have changed it', function (string $code): void {
    expect(localRestatementsOf($code))->toEqual(localRestatement(['statusUnknown' => true, 'contentTypeUnknown' => true, 'bodyUnknown' => true]));
})->with([
    'handed to a call that changes it' => ['$response = make(); accept($response); return $response;'],
    'a chain the reader declines' => ['$response = make(); $response->fresh(); return $response;'],
    'a call made on it that changes it' => ['$response = make(); $response->setNotModified(); return $response;'],
    'a chain ending in a call the reader cannot follow' => ['$response = make(); if ($response->setStatusCode(202)->isOk()) { log(); } return $response;'],
    'a nullsafe call made on it' => ['$response = make(); $response?->setStatusCode(202); return $response;'],
    'a property written through it' => ['$response = make(); $response->headers = null; return $response;'],
    'an offset written through a property of it' => ['$response = make(); $response->headers[\'x\'] = 1; return $response;'],
    'a property unset through it' => ['$response = make(); unset($response->original); return $response;'],
    'captured by a closure' => ['$response = make(); later(function () use ($response) { $response->setStatusCode(500); }); return $response;'],
    'named by an arrow function' => ['$response = make(); later(fn () => $response->setStatusCode(500)); return $response;'],
    // A value that may stand in the local in place of the one restated is not the one restated.
    'replaced on one branch after a status was set' => ['$response = make(); $response->setStatusCode(202); if (ready()) { $response = make(); } return $response;'],
    'bound by a foreach after a status was set' => ['$response = make(); $response->setStatusCode(202); foreach (all() as $response) {} return $response;'],
]);

it('reads a status set after something nobody read changed the response, and nothing else', function (): void {
    // The status is set last, so it is the one sent; the body and the media type nobody read stay unread.
    expect(localRestatementsOf('$response = make(); accept($response); $response->setStatusCode(202); return $response;'))
        ->toEqual(localRestatement(['status' => new LiteralT(202), 'contentTypeUnknown' => true, 'bodyUnknown' => true]));
});

it('finds nothing restated where the response is only read, or restated somewhere that cannot reach the return', function (string $code, string $returned = 'return $response;'): void {
    expect(localRestatementsOf($code, $returned))->toBeNull();
})->with([
    'nothing after it is built' => ['$response = make(); return $response;'],
    'a reader called on it' => ['$response = make(); $status = $response->getStatusCode(); return $response;'],
    'handed to a call that keeps it' => ['$response = make(); logged($response); return $response;'],
    'a chain on a copy it hands back' => ['$response = make(); $response->duplicate()->setStatusCode(500); return $response;'],
    'a header that is not the media type' => ['$response = make(); $response->header(\'X-Total\', \'3\'); return $response;'],
    'a closure that does not capture it' => ['$response = make(); later(function () { $response = make(); $response->setStatusCode(500); }); return $response;'],
    // The local replaced after the status was set: the new value is the one returned.
    'set, then replaced' => ['$response = make(); $response->setStatusCode(202); $response = make(); return $response;'],
    'replaced on a branch before anything was set' => ['$response = make(); if (ready()) { $response = make(); } return $response;'],
    'set after an earlier return' => ['$response = make(); if (ready()) { return $response; } $response->setStatusCode(202); return $response;', 'return $response; }'],
    'another local restated' => ['$response = make(); $other = make(); $other->setStatusCode(500); return $response;'],
    // An arrow function's one expression is its return: no statement of the body it sits in precedes it.
    'returned from an arrow function' => ['$response = make(); $response->setStatusCode(500); return fn () => $response;', '=> $response'],
]);
