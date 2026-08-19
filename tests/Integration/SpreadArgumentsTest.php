<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine coverage for a call whose arguments are assembled elsewhere and spread in. Every reader
 * below indexes a POSITION, and a spread holds a sequence that fills its own and every later one — so a
 * build that reads the written slots documents the argument list itself as the response body, and reads
 * the framework's default for a status the code states. A stub engine cannot stand in for this: the
 * defect is in what the fold hands the readers, and the fold is the engine's.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The single recovered return type of one action on the spread-response controller. */
function spreadReturnType(string $method): ClassT
{
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyze(
        'app/Http/Controllers/SpreadResponseController.php',
        'App\\Http\\Controllers\\SpreadResponseController',
        $method,
    ));

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class);

    return $type;
}

it('reads the payload and the status of a call that writes them', function (): void {
    // The control. Everything the spread hides is written here, and all of it is recovered — which is
    // what makes the three below a widening rather than a reader that gave up.
    $type = spreadReturnType('index');

    expect($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs[0])->toBeInstanceOf(ArrayShapeT::class)
        ->and($type->typeArgs[1])->toEqual(new LiteralT(201));
})->group('fixture');

it('documents nothing about a response()->json() whose arguments are spread in', function (): void {
    // `response()->json(...$this->envelope())` published the ARGUMENT LIST as the response body — a
    // two-element list of an array and an int — with a confident 200 beside it, for a call that sends
    // 201. A bare JsonResponse is what the call still proves.
    $type = spreadReturnType('show');

    expect($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs)->toBe([]);
})->group('fixture');

it('documents nothing about a new JsonResponse whose arguments are spread in', function (): void {
    // The constructor half of the same defect: arg 0 typed the argument list as the body, and arg 1
    // looking absent took Symfony's own 200 for a call that passes 201.
    $type = spreadReturnType('store');

    expect($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs[0])->not->toBeInstanceOf(ArrayShapeT::class)
        ->and($type->typeArgs[1] ?? null)->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('keeps the empty body of a noContent() while widening the status it may carry', function (): void {
    // `noContent()` writes an empty body whatever status it carries, so only the status is unknown here:
    // the void payload stands, and the 204 default does not — this call sends 205.
    $type = spreadReturnType('destroy');

    expect($type->typeArgs[0])->toBeInstanceOf(VoidT::class)
        ->and($type->typeArgs[1] ?? null)->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('keeps every body member of a factory called with a spread, rather than deleting them', function (): void {
    // `DataProblemDocument::make(...$this->arguments(…))` binds every member from a sequence nothing can
    // read. Read as "the call site passed nothing", each member is deleted — and the response is
    // documented with a body it never sends, three keys short. Unbound is the truthful answer.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/SpreadProblemRenderer.php',
        'App\\Exceptions\\SpreadProblemRenderer',
        '__invoke',
    ));

    $members = null;
    foreach ($analysis->returns as $return) {
        $type = $return->type;
        if ($type instanceof ClassT && ($type->typeArgs[3] ?? null) instanceof ArrayShapeT) {
            $members = $type->typeArgs[3];
        }
    }

    expect($members)->toBeInstanceOf(ArrayShapeT::class)
        ->and(array_map(static fn ($field): string => (string) $field->key, $members->fields))
        ->toBe(['type', 'title', 'status', 'detail', 'instance', 'errors']);
})->group('fixture');
