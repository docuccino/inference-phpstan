<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine coverage for a response rendered through a spatie Data object. Two things are opaque without
 * help, and a stub engine can't stand in for either: `Data::toResponse()` declares a bare `JsonResponse`,
 * so the payload is thrown away; and an `application/problem+json` label set as a header mutation rather
 * than a constructor argument is invisible to a constructor fold.
 *
 * What the engine must hand back is the Data CLASS, not an expanded shape — spatie's property semantics are
 * the adapter's business, so the boundary is that the engine names the object and the adapter describes it.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The recovered `JsonResponse` type args for one narrowed arm of the Data-based renderer. */
function dataProblemShape(string $narrowType): array
{
    $analysis = ActionAnalysis::fromArray(['returns' => FixtureRunner::analyzeCallable(
        'app/Exceptions/DataProblemRenderer.php',
        'App\\Exceptions\\DataProblemRenderer',
        '__invoke',
        param: 'e',
        narrowType: $narrowType,
    )['returns']]);

    expect($analysis->returns)->toHaveCount(1);

    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse');

    $contentType = $type->typeArgs[2] ?? null;

    $members = [];
    foreach (($type->typeArgs[3] ?? null)?->fields ?? [] as $field) {
        $members[(string) $field->key] = $field->type;
    }

    return [
        'payload' => $type->typeArgs[0] ?? null,
        'status' => $type->typeArgs[1] ?? null,
        'contentType' => $contentType instanceof LiteralT && is_string($contentType->value) ? $contentType->value : null,
        'members' => $members,
    ];
}

it('recovers the Data class a response body is rendered from', function (string $narrowType): void {
    // Direct arm (Throwable → the 500 branch) and the two-hop HttpException arm both land on
    // toProblemResponse(), so both must see through Data::toResponse().
    $shape = dataProblemShape($narrowType);

    expect($shape['payload'])->toBeInstanceOf(ClassT::class)
        ->and($shape['payload']->fqcn)->toBe('App\\Data\\ProblemDocumentData');
})->with([
    'a two-hop arm through a private branch method' => ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException'],
    'a two-hop arm for a different status' => ['Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException'],
])->group('fixture');

it('reads a class that writes its own response, not spatie\'s default', function (): void {
    // toResponse() is overridden, so the constructor fold owns the answer: its `Content-Type` header and its
    // real status. The payload is still the Data class — `transform()` and the documented schema are the
    // same body. Modelling spatie's toResponse() here instead would have thrown both away.
    $shape = dataProblemShape('RuntimeException');

    expect($shape['payload'])->toBeInstanceOf(ClassT::class)
        ->and($shape['payload']->fqcn)->toBe('App\\Data\\OwnResponseProblemData')
        ->and($shape['contentType'])->toBe('application/problem+json');
})->group('fixture');

// Per-call-site constructor arguments: what the body actually carries, which the class alone cannot say.

it('folds every constructor argument a call site wrote as a literal', function (): void {
    // The fallback arm writes all four at the problem() call, two hops from the `new`, and each one binds
    // through the parameter it was passed into.
    $members = dataProblemShape('LogicException')['members'];

    expect($members['type'])->toEqual(new LiteralT('about:blank'))
        ->and($members['title'])->toEqual(new LiteralT('Internal Server Error'))
        ->and($members['status'])->toEqual(new LiteralT(500))
        ->and($members['detail'])->toEqual(new LiteralT('Something went wrong.'));
})->group('fixture');

it('records an argument that was supplied but does not fold, and omits one never supplied', function (): void {
    // renderHttpProblem passes a literal 'Error' title and three values read off the HttpException. All four
    // are SUPPLIED, which is the fact the doc seam needs — a member passed here is in this body even when
    // its value isn't knowable. `instance`/`errors` are passed by nobody on this path, so they are not
    // members of it at all, whatever the shared schema says about them.
    $members = dataProblemShape('Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException')['members'];

    expect($members)->toHaveKeys(['type', 'title', 'status', 'detail'])
        ->and($members['title'])->toEqual(new LiteralT('Error'))
        ->and($members['type'])->not->toBeInstanceOf(LiteralT::class)
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class)
        ->and($members)->not->toHaveKey('instance')
        ->and($members)->not->toHaveKey('errors');
})->group('fixture');

it('reads a construction a factory hop away, binding its enum accessors and its supplied optionals', function (): void {
    // The object is built inside DataProblemDocument::make(), never where the response is produced, so the
    // members are only recoverable by following the receiver. Once the bound InvoiceProblem case reaches
    // them, `->value` and the two `match ($this)` accessors fold; `errors: $errors ?? new Optional` reads
    // through the parameter, so supplying it at the call site is what puts it in this body.
    $members = dataProblemShape('Illuminate\\Validation\\ValidationException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/unprocessable'))
        ->and($members['title'])->toEqual(new LiteralT('Unprocessable Content'))
        ->and($members['status'])->toEqual(new LiteralT(422))
        ->and($members)->toHaveKeys(['instance', 'errors'])
        // Both read the request / the caller's array: supplied here, and honestly not foldable.
        ->and($members['instance'])->not->toBeInstanceOf(LiteralT::class)
        ->and($members['errors'])->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('reads the arguments of a class that writes its own response', function (): void {
    // The `new` is the receiver of the response-producing call itself — the shortest form of the same fact.
    $shape = dataProblemShape('RuntimeException');

    expect($shape['members']['type'])->toEqual(new LiteralT('about:blank'))
        ->and($shape['members']['status'])->toEqual(new LiteralT(503))
        // …and the RESPONSE status is not folded on the same arm: `new JsonResponse(…, $this->status, …)`
        // reads a property off the object, which no call-site binding reaches. The two halves disagreeing
        // is the seam the adapter has to resolve — the folded member is the one with evidence behind it.
        ->and($shape['status'])->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('recovers a media type re-labelled by a header set on the returned response', function (): void {
    // `$response->headers->set('Content-Type', 'application/problem+json')` — not a constructor argument,
    // so without reading it the body would be documented under application/json.
    expect(dataProblemShape('Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException')['contentType'])
        ->toBe('application/problem+json');
})->group('fixture');

it('refuses to fold a constructor argument written as a credential-named constant', function (): void {
    // `detail: self::SUPPORT_API_KEY` folds like any other constant string, and a folded member is
    // published as an example the emitter keeps — so the honest answer is a supplied-but-unfolded member.
    $members = dataProblemShape('ArithmeticError')['members'];

    expect($members)->toHaveKey('detail')
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class)
        // The innocuous constants beside it still fold, so this is a name-driven refusal, not a dead fold.
        ->and($members['title'])->toEqual(new LiteralT('Error'))
        ->and($members['status'])->toEqual(new LiteralT(500));
})->group('fixture');

it('refuses the same constant behind a `??` default', function (): void {
    // `detail: self::SUPPORT_KEY_OVERRIDE ?? self::SUPPORT_API_KEY` types as the credential's own string,
    // so a guard reading only the outermost expression would fold and publish it. The member is still
    // SUPPLIED — the refusal widens the value, it never drops the field.
    $members = dataProblemShape('JsonException')['members'];

    expect($members)->toHaveKey('detail')
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class)
        ->and($members['title'])->toEqual(new LiteralT('Error'));
})->group('fixture');

it('does not label a branch with the media type another branch of the same helper set', function (): void {
    // toNegotiatedResponse() builds both branches into `$response`; the plain branch (the one documented,
    // being first) writes no Content-Type, so the body must not inherit the other branch's label.
    expect(dataProblemShape('ArithmeticError')['contentType'])->toBeNull();
})->group('fixture');
