<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A response built into a local is sent as the statements after it leave it: `$response->setStatusCode(202);`
 * on a line of its own sends a 202 exactly as `->setStatusCode(202)` chained onto the call does, whatever kind
 * of response it is. A status set on only some paths is one more the response can be sent with, and anything
 * that may have changed the response in a way the build cannot read leaves no status, media type or body of
 * the one it was built with standing.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function statementStatusAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/StatementStatusController.php',
        'App\\Http\\Controllers\\StatementStatusController',
        ['json', 'plain', 'stream', 'redirect', 'constructed', 'subclass', 'branch', 'twice', 'replaced', 'helper', 'helperHeader', 'chainedStatement', 'unread', 'content', 'header', 'headerBag', 'reads', 'thrown'],
    );
}

function statementStatusReturn(string $method): DType
{
    $returns = ActionAnalysis::fromArray(statementStatusAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    return $returns[0]->type;
}

const STATEMENT_STATUS_JSON = 'Illuminate\\Http\\JsonResponse';

it('documents the status a statement sets on the returned local, for every kind of response', function (string $method, string $class, int $status): void {
    expect(statementStatusReturn($method)->typeArgs[1] ?? null)->toEqual(new LiteralT($status))
        ->and(statementStatusReturn($method)->fqcn ?? null)->toBe($class);
})->with([
    'response()->json(…)' => ['json', STATEMENT_STATUS_JSON, 202],
    'response($text)' => ['plain', STATEMENT_STATUS_JSON, 202],
    'response()->stream($cb)' => ['stream', 'Symfony\\Component\\HttpFoundation\\StreamedResponse', 202],
    'redirect($url)' => ['redirect', 'Illuminate\\Http\\RedirectResponse', 303],
    'new JsonResponse(…)' => ['constructed', STATEMENT_STATUS_JSON, 202],
    // An application's subclass sends the status its framework parent's setter sets, as the parent would.
    'new GoneJsonResponse' => ['subclass', STATEMENT_STATUS_JSON, 404],
    // The last one to run is the one sent.
    'setStatusCode(201), then setStatusCode(202)' => ['twice', STATEMENT_STATUS_JSON, 202],
    'setStatusCode(202)->header(…) as one statement' => ['chainedStatement', STATEMENT_STATUS_JSON, 202],
    // The framework sends the response an HttpResponseException carries as the code left it.
    'thrown after setStatusCode(409)' => ['thrown', STATEMENT_STATUS_JSON, 409],
])->group('fixture');

it('keeps the body the response was built with when a statement sets only its status', function (): void {
    expect(statementStatusReturn('json'))->toEqual(new ClassT(STATEMENT_STATUS_JSON, [
        new ArrayShapeT([new ArrayShapeField('queued', new LiteralT(true))]),
        new LiteralT(202),
    ]));
})->group('fixture');

it('documents both statuses where the statement setting one runs on only some paths', function (): void {
    // Without `?defer` the response goes out with the 200 it was built with; with it, 202.
    expect(statementStatusReturn('branch')->typeArgs[1] ?? null)->toEqual(UnionT::of([new LiteralT(200), new LiteralT(202)]));
})->group('fixture');

it('documents the response the local holds once it is replaced, not the one a statement set before', function (): void {
    expect(statementStatusReturn('replaced'))->toEqual(new ClassT(STATEMENT_STATUS_JSON, [
        new ArrayShapeT([new ArrayShapeField('created', new LiteralT(true))]),
        new LiteralT(201),
    ]));
})->group('fixture');

it('claims no status where a statement sets one nobody can read', function (): void {
    // The 200 the response was built with is replaced by one read off the request; publishing it would be a
    // status the endpoint may never send.
    expect(statementStatusReturn('unread')->typeArgs[1] ?? null)->toEqual(new UnknownT('status not folded'));
})->group('fixture');

it('claims nothing the response was built with where something the build cannot read may have changed it', function (): void {
    // A helper handed the response may have replaced the status and the body, so neither the 200 nor the body
    // it was built with is published.
    expect(statementStatusReturn('helper'))->toEqual(new ClassT(STATEMENT_STATUS_JSON, [new UnknownT('payload not folded'), new UnknownT('status not folded')]));
})->group('fixture');

it('documents the body a statement replaces, beside the status it leaves alone', function (string $method, ArrayShapeT|LiteralT $body, int $status, ?string $mediaType): void {
    // `setContent()` replaces the content and nothing else, so the 202 the response was built with is still
    // the one sent; a status set on an application's subclass leaves the body its constructor handed the
    // framework's.
    expect(statementStatusReturn($method))->toEqual(new ClassT(STATEMENT_STATUS_JSON, [$body, new LiteralT($status), ...($mediaType === null ? [] : [new LiteralT($mediaType)])]));
})->with([
    'setContent(\'accepted\')' => ['content', new LiteralT('accepted'), 202, 'text/html'],
    'setStatusCode(404) on new GoneJsonResponse' => ['subclass', new ArrayShapeT([new ArrayShapeField('message', new LiteralT('This resource is gone.'))]), 404, null],
])->group('fixture');

it('documents the media type a header statement sets', function (): void {
    expect(statementStatusReturn('header'))->toEqual(new ClassT(STATEMENT_STATUS_JSON, [new LiteralT('id,total'), new LiteralT(202), new LiteralT('text/csv')]));
})->group('fixture');

it('leaves the response as it was built where a statement only reads it', function (string $method): void {
    // Reading the status or the data, or setting a header that is not the media type, changes nothing sent —
    // in a helper handed the response too, where `header()` handing the response back to it is no escape.
    expect(statementStatusReturn($method))->toEqual(new ClassT(STATEMENT_STATUS_JSON, [
        new ArrayShapeT([new ArrayShapeField('queued', new LiteralT(true))]),
        new LiteralT(202),
    ]));
})->with(['headerBag', 'reads', 'helperHeader'])->group('fixture');
