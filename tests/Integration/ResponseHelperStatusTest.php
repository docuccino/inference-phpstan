<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A plain response the factory makes — `response($content, $status)`, `response()->make()`,
 * `response()->view()` — is sent with the status its call names, which the framework's 200 of the bare
 * `Illuminate\Http\Response` would contradict. The status is read as every other response status is
 * ({@see CaseStatusResponseTest}): a literal, a class constant, a read on an enum case, or a helper's
 * parameter bound at its call site.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function responseHelperStatusAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/ResponseHelperStatusController.php',
        'App\\Http\\Controllers\\ResponseHelperStatusController',
        ['literal', 'constant', 'caseRead', 'named', 'chained', 'factoryMake', 'view', 'helperStatus', 'helperCase', 'plainOk', 'unread'],
    );
}

function responseHelperStatusReturn(string $method): DType
{
    $returns = ActionAnalysis::fromArray(responseHelperStatusAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    return $returns[0]->type;
}

it('publishes the status a plain response is made with', function (string $method, int $status): void {
    $type = responseHelperStatusReturn($method);

    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->typeArgs[1] ?? null)->toEqual(new LiteralT($status));
})->with([
    'response(\'\', 410)' => ['literal', 410],
    'response($body, Response::HTTP_GONE)' => ['constant', 410],
    'response(\'\', Case->statusCode())' => ['caseRead', 410],
    'response(status: 410)' => ['named', 410],
    'response(\'\')->setStatusCode(410)' => ['chained', 410],
    'response()->make(\'\', Case->value)' => ['factoryMake', 423],
    'response()->view($view, [], 410)' => ['view', 410],
    // A helper's status is the one its call site hands it.
    '$this->plain($body, 410)' => ['helperStatus', 410],
    '$this->faulted(Case), reading $fault->statusCode()' => ['helperCase', 423],
])->group('fixture');

it('reads the body as Laravel sends it', function (string $method, DType $payload, ?DType $mediaType): void {
    // An empty content is no body at all; a view is the view it renders. A string is sent as it is, not as
    // JSON, and labelled `text/html`, as Symfony labels any body nobody else did.
    $type = responseHelperStatusReturn($method);

    expect($type->typeArgs[0] ?? null)->toEqual($payload)
        ->and($type->typeArgs[2] ?? null)->toEqual($mediaType);
})->with([
    'empty content' => ['literal', new VoidT, null],
    'no content argument' => ['named', new VoidT, null],
    'a string' => ['constant', new LiteralT('archived'), new LiteralT('text/html')],
    'a string a helper is handed' => ['helperStatus', ScalarT::string(), new LiteralT('text/html')],
    'a view' => ['view', new ClassT('Illuminate\\Contracts\\View\\View'), null],
])->group('fixture');

it('documents text sent with the framework\'s 200, which the bare class cannot describe', function (): void {
    // `response('created')` is a 200 whose body is that text, not the body nobody read that the bare class publishes.
    expect(responseHelperStatusReturn('plainOk'))->toEqual(new ClassT('Illuminate\\Http\\JsonResponse', [new LiteralT('created'), new LiteralT(200), new LiteralT('text/html')]));
})->group('fixture');

it('claims no status where the one the call states cannot be read', function (): void {
    // The call replaced the 200 with a status read off the request: publishing 200 would be a status the
    // endpoint may never send.
    $status = responseHelperStatusReturn('unread')->typeArgs[1] ?? null;

    expect($status)->toBeInstanceOf(UnknownT::class);
})->group('fixture');

it('depends on the enum the status was read off', function (string $method): void {
    $files = array_map(basename(...), responseHelperStatusAnalyses()[$method]['dependencyFiles']);

    expect($files)->toContain('ExportFault.php');
})->with(['caseRead', 'factoryMake', 'helperCase'])->group('fixture');
