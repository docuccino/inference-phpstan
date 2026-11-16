<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A render callback handing the exception to a static helper whose `match` picks a `Problem`, which the
 * framework sends through the problem's own `toResponse()` (`app/Exceptions/Rfc9457`). The server answers
 * each exception with the problem its arm builds, so that is what the callback is read to answer, narrowed
 * to the exception: the arm the helper's `match` reaches, and the problem's body and status read with each
 * property holding what that arm built it with. What the document makes of it is held by
 * {@see ProblemDetailsHandlerGoldenTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The callback the fixture registers, read for one thrown class: `problem` or `rendered`. */
function rfc9457Callback(string $callback, string $narrowType): ActionAnalysis
{
    $needle = $callback === 'problem' ? 'Problems::for($e)' : 'Problems::render($e, $request)';
    $line = 0;
    foreach (explode("\n", (string) file_get_contents(FixtureRunner::path('app/Exceptions/Rfc9457/ProblemCallbacks.php'))) as $index => $text) {
        if (str_contains($text, $needle)) {
            $line = $index + 1;
        }
    }
    expect($line)->toBeGreaterThan(0);

    return ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/Rfc9457/ProblemCallbacks.php',
        '',
        '',
        line: $line,
        param: 'e',
        narrowType: $narrowType,
    ));
}

/**
 * The one response the analysis answers with: its status if it folded, the body's members as each was read
 * (a literal's value, else its kind), and its media type.
 *
 * @return array{status: int|null, members: array<string, mixed>, media: string|null}
 */
function rfc9457Response(ActionAnalysis $analysis): array
{
    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse');

    $body = $type->typeArgs[0] ?? null;
    expect($body)->toBeInstanceOf(ArrayShapeT::class);

    $members = [];
    foreach ($body->fields as $field) {
        $members[(string) $field->key] = $field->type instanceof LiteralT ? $field->type->value : $field->type->toArray()['kind'];
    }

    $read = static fn (?DType $arg): mixed => $arg instanceof LiteralT ? $arg->value : null;

    return ['status' => $read($type->typeArgs[1] ?? null), 'members' => $members, 'media' => $read($type->typeArgs[2] ?? null)];
}

it('answers each exception with the problem the helper’s arm builds for it', function (string $narrowType, ?int $status, array $members): void {
    $response = rfc9457Response(rfc9457Callback('problem', $narrowType));

    // The problem sends its own status, so a status its arm built it with is the response's — and where the
    // arm passed one the build cannot read (the exception's own), neither is claimed.
    expect($response['status'])->toBe($status)
        ->and($response['media'])->toBe('application/problem+json')
        ->and($response['members'])->toBe($members);
})->with([
    'built in the arm' => ['App\\Exceptions\\OrderConflictException', 409, [
        'type' => 'https://errors.test/problems/conflict', 'title' => 'Conflict', 'status' => 409, 'detail' => 'scalar', 'instance' => 'scalar',
    ]],
    // The extension members the arm hands the problem are spread into its body.
    'built by a helper handed the exception' => ['Illuminate\\Validation\\ValidationException', null, [
        'type' => 'https://errors.test/problems/validation', 'title' => 'Unprocessable Content', 'status' => 'scalar', 'detail' => 'scalar', 'instance' => 'scalar', 'errors' => 'map',
    ]],
    // A parameter the factory does not pass on takes its default, and an extension it passes none of adds nothing.
    'built by a factory' => ['Illuminate\\Auth\\AuthenticationException', 401, [
        'type' => 'about:blank', 'title' => 'Unauthenticated', 'status' => 401, 'detail' => 'Sign in to continue.', 'instance' => 'scalar',
    ]],
    'built with the status the exception carries' => ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException', null, [
        'type' => 'about:blank', 'title' => 'Request refused', 'status' => 'unknown', 'detail' => 'scalar', 'instance' => 'scalar',
    ]],
    'the default arm' => ['RuntimeException', 500, [
        'type' => 'about:blank', 'title' => 'Server Error', 'status' => 500, 'detail' => 'Something went wrong on our side.', 'instance' => 'scalar',
    ]],
])->group('fixture');

it('reads the same problem where the helper sends it itself', function (string $narrowType): void {
    // `Problems::render()` returns null outside the API and sends the problem it picks otherwise. The null is
    // the framework's to answer, so the API's answer is the problem, exactly as when the callback hands it over.
    expect(rfc9457Callback('rendered', $narrowType)->returns[0]->type->toArray())
        ->toBe(rfc9457Callback('problem', $narrowType)->returns[0]->type->toArray());
})->with([
    'App\\Exceptions\\OrderConflictException',
    'Illuminate\\Auth\\AuthenticationException',
    'RuntimeException',
])->group('fixture');

it('reads nothing for an arm whose problem was built where the exception was thrown', function (): void {
    // The exception carries a problem chosen wherever it was thrown, which no arm here builds, so no body or
    // status is claimed for it: the handler tier declines and says so.
    $analysis = rfc9457Callback('problem', 'App\\Exceptions\\Rfc9457\\ProblemException');

    expect($analysis->returns)->toHaveCount(1)
        ->and($analysis->returns[0]->type)->not->toBeInstanceOf(ClassT::class);
})->group('fixture');

it('keys the answer on the helper and the problem as well as the callback', function (): void {
    // Editing the helper's `match` or the problem's `toResponse()` changes what every error is answered with.
    $files = array_map(basename(...), rfc9457Callback('problem', 'App\\Exceptions\\OrderConflictException')->dependencyFiles);

    expect($files)->toContain('ProblemCallbacks.php', 'Problems.php', 'Problem.php');
})->group('fixture');
