<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\ResponseFactoryCall;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Where `response()->json()` and `response()->noContent()` take their body and status, for both readers of
 * those calls. The framework's default status is only true of a call that provably passed none: a spread
 * nobody can read may be carrying one.
 */
it('reads the body and status wherever the call writes them', function (string $code, ?array $expected): void {
    $call = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';')[0]->expr;
    $read = ResponseFactoryCall::arguments($call);
    $print = static fn (?Node\Expr $expr): ?string => $expr === null ? null : (new Standard)->prettyPrintExpr($expr);

    expect($read === null ? null : [$read['method'], $print($read['body']), $print($read['status']), $read['defaultStatus']])->toBe($expected);
})->with([
    'json, positional' => ['response()->json($data, $code)', ['json', '$data', '$code', 200]],
    'json, named' => ['response()->json(status: $code, data: $data)', ['json', '$data', '$code', 200]],
    'json, no status: the framework\'s 200' => ['response()->json($data)', ['json', '$data', null, 200]],
    'json, a spread nobody can read' => ['response()->json(...$args)', ['json', null, null, null]],
    'noContent, positional' => ['response()->noContent($code)', ['noContent', null, '$code', 204]],
    'noContent, none: the framework\'s 204' => ['response()->noContent()', ['noContent', null, null, 204]],
    'any casing' => ['response()->JSON($data)', ['json', '$data', null, 200]],
    'another method' => ['response()->download($path)', null],
    'a dynamic name' => ['response()->{$method}($data)', null],
    'a first-class callable' => ['response()->json(...)', null],
]);
