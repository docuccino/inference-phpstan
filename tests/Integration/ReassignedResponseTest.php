<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * `$response = $response->header(…)` sets the header on the response the local held before the statement and
 * names the same object again, so it sends exactly what `return $response->header(…)` would. The `$response`
 * on the right is that earlier value — a parameter's, in a helper — never the assignment it sits in, and
 * reading it as that assignment had the build follow it into itself until memory ran out.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function reassignedResponseAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/ReassignedResponseController.php',
        'App\\Http\\Controllers\\ReassignedResponseController',
        [
            'helper', 'helperReturning', 'helperHeaders', 'helperTwice', 'helperThroughLocal', 'helperProblem',
            'helperProblemReturning', 'named', 'reassigned', 'inline', 'handed',
        ],
    );
}

function reassignedResponseReturn(string $method): DType
{
    $returns = ActionAnalysis::fromArray(reassignedResponseAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    return $returns[0]->type;
}

const REASSIGNED_RESPONSE_JSON = 'Illuminate\\Http\\JsonResponse';

it('documents a helper reassigning the response it is handed as the helper returning the chain unnamed', function (string $method, string $unnamed, DType $expected): void {
    // A header that is not the media type changes nothing documented, so the helper answers what it was handed,
    // which is read no further than its declared class; a media type set on it is laid over that, as the chain
    // returned without naming it lays it. Two reassignments, or one through a second local, are the same.
    expect(reassignedResponseReturn($method))->toEqual(reassignedResponseReturn($unnamed))
        ->and(reassignedResponseReturn($method))->toEqual($expected);
})->with([
    '$response = $response->header(…)' => ['helper', 'helperReturning', new ClassT(REASSIGNED_RESPONSE_JSON)],
    '$response = $response->withHeaders([…])' => ['helperHeaders', 'helperReturning', new ClassT(REASSIGNED_RESPONSE_JSON)],
    'reassigned twice' => ['helperTwice', 'helperReturning', new ClassT(REASSIGNED_RESPONSE_JSON)],
    '$allowed = $response->header(…); $response = $allowed->header(…)' => ['helperThroughLocal', 'helperReturning', new ClassT(REASSIGNED_RESPONSE_JSON)],
    'the helper\'s result named in the action' => ['named', 'helperReturning', new ClassT(REASSIGNED_RESPONSE_JSON)],
    '$response = $response->header(\'Content-Type\', …)' => ['helperProblem', 'helperProblemReturning', new ClassT(REASSIGNED_RESPONSE_JSON, [
        new UnknownT('payload not folded'),
        new UnknownT('status not folded'),
        new LiteralT('application/problem+json'),
    ])],
])->group('fixture');

it('documents a response reassigned in the action that built it as it was built', function (): void {
    // The header changes nothing documented, so the 202 and the body the factory built are what is sent.
    expect(reassignedResponseReturn('inline'))->toEqual(new ClassT(REASSIGNED_RESPONSE_JSON, [
        new ArrayShapeT([new ArrayShapeField('queued', new LiteralT(true))]),
        new LiteralT(202),
    ]));
})->group('fixture');

it('claims nothing of a response it cannot see built', function (string $method): void {
    // An action handed its response, and one reassigning the response to what a helper hands back after it
    // was built: neither says which status or body goes out, so neither publishes one.
    expect(reassignedResponseReturn($method)->typeArgs[1] ?? new UnknownT('status not folded'))->toBeInstanceOf(UnknownT::class);
})->with(['handed', 'reassigned'])->group('fixture');
