<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine truth for the inferred-handler tier (design §6 flagship): the ACTUAL PHPStan/Larastan
 * engine recovers per-exception response shapes+statuses from a Problem-Details-style
 * `render(Throwable $e)` analysed once per thrown type with the parameter narrowed (native
 * `instanceof` narrowing, source-order-first-match), and from a per-exception render-callback closure
 * analysed by file+line. This is engine truth a stub cannot stand in for.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array{status: int, keys: list<string>}
 */
function narrowedRender(string $narrowType): array
{
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/ProblemRenderer.php',
        'App\\Exceptions\\ProblemRenderer',
        'render',
        param: 'e',
        narrowType: $narrowType,
    ));

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse');

    $status = $type->typeArgs[1] ?? null;
    $payload = $type->typeArgs[0] ?? null;
    expect($status)->toBeInstanceOf(LiteralT::class);

    // The payload is an array shape; collect its top-level keys.
    $keys = [];
    if ($payload !== null) {
        foreach ($payload->toArray()['fields'] ?? [] as $field) {
            $keys[] = $field['key'] ?? '';
        }
    }

    return ['status' => (int) $status->value, 'keys' => $keys];
}

it('recovers the validation branch (422 + errors) via narrowed catch-all analysis', function (): void {
    $result = narrowedRender('Illuminate\\Validation\\ValidationException');

    expect($result['status'])->toBe(422)
        ->and($result['keys'])->toContain('type', 'title', 'status', 'errors');
})->group('fixture');

it('recovers the authentication branch (401) via narrowed catch-all analysis', function (): void {
    $result = narrowedRender('Illuminate\\Auth\\AuthenticationException');

    expect($result['status'])->toBe(401)
        ->and($result['keys'])->toContain('type', 'title', 'status')
        ->and($result['keys'])->not->toContain('errors');
})->group('fixture');

it('falls through to the default branch (500) for an unmatched exception type', function (): void {
    $result = narrowedRender('RuntimeException');

    expect($result['status'])->toBe(500);
})->group('fixture');

it('raises an ambiguity diagnostic when a negated guard shadows the specific branch', function (): void {
    // renderAmbiguous puts `if (! ($e instanceof OutOfStockException))` first, so the broad default is
    // chosen even when narrowing to OutOfStockException — the source-order-first-match misfires and the
    // narrowing-honesty diagnostic (B2) must flag it rather than pass the shape off as unambiguous.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/ProblemRenderer.php',
        'App\\Exceptions\\ProblemRenderer',
        'renderAmbiguous',
        param: 'e',
        narrowType: 'App\\Exceptions\\OutOfStockException',
    ));

    $codes = array_map(static fn ($d): string => $d->code, $analysis->diagnostics);
    expect($codes)->toContain('inference.ambiguous-narrowing');
})->group('fixture');

it('does not raise the ambiguity diagnostic for the ordinary sequential-instanceof renderer', function (): void {
    // The plain `render` (exact instanceof branches ahead of the default) is unambiguous — no diagnostic.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/ProblemRenderer.php',
        'App\\Exceptions\\ProblemRenderer',
        'render',
        param: 'e',
        narrowType: 'Illuminate\\Validation\\ValidationException',
    ));

    $codes = array_map(static fn ($d): string => $d->code, $analysis->diagnostics);
    expect($codes)->not->toContain('inference.ambiguous-narrowing');
})->group('fixture');

it('recovers an invokable renderer’s shape via method analysis of __invoke', function (): void {
    // The common real-world shape: `$exceptions->render(new InvokableProblemRenderer)`. Laravel wraps it as a
    // method-backed closure, so the tier analyses `__invoke` — proven here against the real engine, with
    // `$e` narrowed to a specific thrown type, exactly as the mapper drives it.
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/InvokableProblemRenderer.php',
        'App\\Exceptions\\InvokableProblemRenderer',
        '__invoke',
        param: 'e',
        narrowType: 'App\\Exceptions\\OutOfStockException',
    ));

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and(($type->typeArgs[1] ?? null)?->value)->toBe(409);

    $keys = array_map(static fn (array $f): string => $f['key'] ?? '', $type->typeArgs[0]->toArray()['fields'] ?? []);
    expect($keys)->toContain('type', 'title', 'status', 'instance');
})->group('fixture');

it('recovers a per-exception render-callback closure by file+line', function (): void {
    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/RenderCallbacks.php'));
    $line = 0;
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, 'function (OutOfStockException')) {
            $line = $index + 1;

            break;
        }
    }
    expect($line)->toBeGreaterThan(0);

    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RenderCallbacks.php',
        '',
        '',
        line: $line,
    ));

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and(($type->typeArgs[1] ?? null)?->value)->toBe(409);

    $keys = array_map(static fn (array $f): string => $f['key'] ?? '', $type->typeArgs[0]->toArray()['fields'] ?? []);
    expect($keys)->toContain('error', 'detail');
})->group('fixture');
