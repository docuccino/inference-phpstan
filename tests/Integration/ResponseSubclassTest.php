<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A response class the application derives from the framework's is sent with the status its constructor
 * hands the framework's: `new GoneResponse` whose constructor calls `parent::__construct('', 410)` answers
 * 410, never the 200 the framework's own default would. That is the same fact an `HttpException` subclass
 * states in its `parent::__construct()`, read by the same walk, so a literal, a constant, an enum case's
 * read, a parameter of the class's own constructor filled at the `new` (or its default), a status set in
 * that constructor and one two classes up all fold the way they do for a thrown exception, and cases that
 * disagree state none. A JSON or plain response's body and media type are read off the same constructors, so
 * a class fixing its body publishes that body; a file, stream or redirect keeps its own class and carries the status.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function responseSubclassAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/ResponseSubclassController.php',
        'App\\Http\\Controllers\\ResponseSubclassController',
        ['gone', 'goneMade', 'retryLater', 'retryPassed', 'retryNamed', 'retryHelper', 'retryHelperDefault', 'moved', 'locked', 'faulted', 'faultChosen', 'partial', 'accepted', 'expired', 'conflict', 'goneJson', 'unavailable', 'quota', 'branching', 'goneResult'],
    );
}

function responseSubclassReturn(string $method): DType
{
    $returns = ActionAnalysis::fromArray(responseSubclassAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    return $returns[0]->type;
}

const RESPONSE_SUBCLASS_JSON = 'Illuminate\\Http\\JsonResponse';

it('sends a derived JSON or plain response with the status its constructor hands the framework\'s', function (string $method, int $status): void {
    $type = responseSubclassReturn($method);

    // Emitted as the framework response it is, whose status slot the adapter reads.
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn ?? null)->toBe(RESPONSE_SUBCLASS_JSON)
        ->and($type->typeArgs[1] ?? null)->toEqual(new LiteralT($status));
})->with([
    'parent::__construct(\'\', 410)' => ['gone', 410],
    'GoneResponse::make(), building new static' => ['goneMade', 410],
    // A public constructor's status is the caller's, or its default where the caller passes none.
    'new RetryLaterResponse($data), defaulting to 503' => ['retryLater', 503],
    'new RetryLaterResponse($data, 429)' => ['retryPassed', 429],
    'new RetryLaterResponse(status: 429, data: …)' => ['retryNamed', 429],
    // A helper's own parameter, bound at the helper's call as any other status it forwards.
    '$this->retry(429)' => ['retryHelper', 429],
    '$this->retry(), defaulting to 503' => ['retryHelperDefault', 503],
    'Symfony\'s response handed 202' => ['accepted', 202],
    '$this->setStatusCode(410) after parent::__construct()' => ['expired', 410],
    'parent::__construct([…], 409), two classes below the framework' => ['conflict', 409],
    'parent::__construct([…], 410)' => ['goneJson', 410],
    // Leaving the parent's defaulted slot empty passes the parent's default.
    'parent::__construct([…]) below a parent defaulting to 503' => ['unavailable', 503],
    // A private constructor's default is every instance's, as an exception's is.
    'QuotaResponse::exceeded(), a private constructor defaulting to 429' => ['quota', 429],
    // The sweep's sibling, already read before: a Responsable rendering the factory's JSON response.
    'a Responsable returning response()->json([…], 410)' => ['goneResult', 410],
])->group('fixture');

it('sends a derived file, stream or redirect as its own class, with the status its constructor hands the framework\'s', function (string $method, string $class, int $status): void {
    // The class names what the body is — a redirect's `Location`, a stream's bytes — so it is kept, and the
    // status rides where a `JsonResponse` carries its own.
    expect(responseSubclassReturn($method))->toEqual(new ClassT('App\\Http\\Responses\\'.$class, [new UnknownT('payload not folded'), new LiteralT($status)]));
})->with([
    'a redirect handed Response::HTTP_MOVED_PERMANENTLY' => ['moved', 'MovedResponse', 301],
    'a stream handed ExportFault::Locked->value' => ['locked', 'LockedStreamResponse', 423],
    'a stream reading the case its caller hands it' => ['faulted', 'FaultStreamResponse', 410],
    'a file handed 206' => ['partial', 'PartialFileResponse', 206],
])->group('fixture');

it('claims no status where the cases handed to the class disagree', function (): void {
    // Locked answers 423 and Gone 410, and the request picks one: either alone is a status half the
    // requests are never sent, so none is claimed, as a throw choosing between the same cases claims none.
    expect(responseSubclassReturn('faultChosen'))->toEqual(new ClassT(
        'App\\Http\\Responses\\FaultStreamResponse',
        [new UnknownT('payload not folded'), new UnknownT('status not folded')],
    ));
})->group('fixture');

it('publishes the body a derived response hands the framework\'s', function (string $method, DType $body): void {
    expect(responseSubclassReturn($method)->typeArgs[0] ?? null)->toEqual($body);
})->with([
    // A fixed array is the body every instance sends.
    'a fixed body' => ['goneJson', new ArrayShapeT([new ArrayShapeField('message', new LiteralT('This resource is gone.'))])],
    'a fixed body two classes below the framework' => ['conflict', new ArrayShapeT([
        new ArrayShapeField('title', new LiteralT('Conflict')),
        new ArrayShapeField('detail', ScalarT::string()),
    ])],
    // A body handed through untouched is the one the `new` passes.
    'the body a construction passes' => ['retryPassed', new ArrayShapeT([new ArrayShapeField('retry', new LiteralT(true))])],
    'a fixed body below a parent handing it through' => ['unavailable', new ArrayShapeT([new ArrayShapeField('retry', new LiteralT(false))])],
    // A plain response's empty content is no body at all, as the factory's `response('', 410)` is.
    'a plain response handed \'\'' => ['gone', new VoidT],
    'a plain response defaulting its content to \'\'' => ['expired', new VoidT],
])->group('fixture');

it('publishes the media type a derived response\'s parent fixes in the headers it hands the framework\'s', function (): void {
    // `ProblemResponse` hands `['Content-Type' => 'application/problem+json']` up, whatever problem extends it.
    expect(responseSubclassReturn('conflict')->typeArgs[2] ?? null)->toEqual(new LiteralT('application/problem+json'))
        ->and(responseSubclassReturn('goneJson')->typeArgs[2] ?? null)->toBeNull();
})->group('fixture');

it('leaves a class whose status is set on a branch the bare class it is', function (): void {
    // `if (…) { $this->setStatusCode(204); }` is a 204 or a 200, and no one of them is the class's.
    expect(responseSubclassReturn('branching'))->toEqual(new ClassT('App\\Http\\Responses\\BranchingResponse'));
})->group('fixture');

it('depends on every class the status and body were read through', function (string $method, array $files): void {
    // A class that changes what it hands its parent must rebuild every route returning it.
    $read = array_map(basename(...), responseSubclassAnalyses()[$method]['dependencyFiles']);

    expect(array_values(array_intersect($files, $read)))->toBe($files);
})->with([
    'the class' => ['gone', ['GoneResponse.php']],
    'the class and the parent it extends' => ['unavailable', ['RetryLaterResponse.php', 'UnavailableResponse.php']],
    'the class and the enum its status is read off' => ['locked', ['ExportFault.php', 'LockedStreamResponse.php']],
])->group('fixture');
