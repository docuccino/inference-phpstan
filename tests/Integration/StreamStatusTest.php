<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A stream or file response is sent with the status its call names: `response()->stream($cb, 202)` answers
 * 202, not the framework's 200 the bare `StreamedResponse` would document. The status is read as every
 * other response status is ({@see ResponseHelperStatusTest}): a literal, a class constant, a read on an
 * enum case, a choice, a chain, or a helper's parameter bound at its call site. It is carried on the class
 * the response is, never on a `JsonResponse`, because the body is a stream whose media type the call site
 * names.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function streamStatusAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/StreamStatusController.php',
        'App\\Http\\Controllers\\StreamStatusController',
        ['stream', 'streamJson', 'constant', 'caseRead', 'named', 'choice', 'chained', 'local', 'facade', 'constructed', 'constructedJson', 'constructedFile', 'helperStatus', 'helperDefault', 'helperCase', 'streamOk', 'streamDownload', 'events', 'unread'],
    );
}

function streamStatusReturn(string $method): DType
{
    $returns = ActionAnalysis::fromArray(streamStatusAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    return $returns[0]->type;
}

const STREAM_STATUS_STREAMED = 'Symfony\\Component\\HttpFoundation\\StreamedResponse';
const STREAM_STATUS_JSON = 'Symfony\\Component\\HttpFoundation\\StreamedJsonResponse';
const STREAM_STATUS_FILE = 'Symfony\\Component\\HttpFoundation\\BinaryFileResponse';

it('carries the status a stream or file is sent with on its own class', function (string $method, string $class, int $status): void {
    expect(streamStatusReturn($method))->toEqual(new ClassT($class, [new UnknownT('payload not folded'), new LiteralT($status)]));
})->with([
    'response()->stream($cb, 202)' => ['stream', STREAM_STATUS_STREAMED, 202],
    'response()->streamJson($data, 206)' => ['streamJson', STREAM_STATUS_JSON, 206],
    'response()->stream($cb, Response::HTTP_ACCEPTED)' => ['constant', STREAM_STATUS_STREAMED, 202],
    'response()->stream($cb, Case->value)' => ['caseRead', STREAM_STATUS_STREAMED, 410],
    'response()->streamJson(status: 206, data: …)' => ['named', STREAM_STATUS_JSON, 206],
    'response()->stream($cb)->setStatusCode(202)' => ['chained', STREAM_STATUS_STREAMED, 202],
    'a local made with 202' => ['local', STREAM_STATUS_STREAMED, 202],
    'Response::streamJson($data, 206)' => ['facade', STREAM_STATUS_JSON, 206],
    'new StreamedResponse($cb, 202)' => ['constructed', STREAM_STATUS_STREAMED, 202],
    'new StreamedJsonResponse($data, 206)' => ['constructedJson', STREAM_STATUS_JSON, 206],
    'new BinaryFileResponse($file, 206)' => ['constructedFile', STREAM_STATUS_FILE, 206],
    // A helper's status is the one its call site hands it, or its parameter's default.
    '$this->streamed(202)' => ['helperStatus', STREAM_STATUS_STREAMED, 202],
    '$this->streamed(), defaulting to 200' => ['helperDefault', STREAM_STATUS_STREAMED, 200],
    '$this->faulted(Case), reading $fault->statusCode()' => ['helperCase', STREAM_STATUS_STREAMED, 423],
])->group('fixture');

it('carries every status a choice can send', function (): void {
    // `$partial ? 206 : 200` is two responses with the same body, as two returns would be.
    expect(streamStatusReturn('choice')->typeArgs[1] ?? null)->toEqual(UnionT::of([new LiteralT(200), new LiteralT(206)]));
})->group('fixture');

it('leaves a stream sent with its default, or by a call taking no status, as the bare class', function (string $method, string $class): void {
    // `stream($cb, 200, …)` is `stream($cb, …)`, and `streamDownload()` and `eventStream()` take no status at
    // all: each is a 200, which is what the bare class already publishes.
    expect(streamStatusReturn($method))->toEqual(new ClassT($class));
})->with([
    'response()->stream($cb, 200, $headers)' => ['streamOk', STREAM_STATUS_STREAMED],
    'response()->streamDownload($cb, $name)' => ['streamDownload', STREAM_STATUS_STREAMED],
    'response()->eventStream($cb)' => ['events', STREAM_STATUS_STREAMED],
])->group('fixture');

it('claims no status where the one the call states cannot be read', function (): void {
    // The call replaced the 200 with a status read off the request: publishing 200 would be a status the
    // endpoint may never send.
    $type = streamStatusReturn('unread');

    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn ?? null)->toBe(STREAM_STATUS_STREAMED)
        ->and($type->typeArgs[1] ?? null)->toBeInstanceOf(UnknownT::class);
})->group('fixture');

it('depends on the enum the status was read off', function (string $method): void {
    $files = array_map(basename(...), streamStatusAnalyses()[$method]['dependencyFiles']);

    expect($files)->toContain('ExportFault.php');
})->with(['caseRead', 'helperCase'])->group('fixture');
