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

/** The 1-based line of the first line in `RenderCallbacks.php` containing `$needle`. */
function renderCallbackLine(string $needle): int
{
    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/RenderCallbacks.php'));
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, $needle)) {
            return $index + 1;
        }
    }

    return 0;
}

it('recovers a per-exception render-callback closure by file+line', function (): void {
    $line = renderCallbackLine('function (OutOfStockException');
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

it('recovers nothing for a line carrying TWO render callbacks', function (): void {
    // A callback is located by what `ReflectionFunction` reports, which is a file and a line — and two
    // closures written on one line share both. Neither can be told from the other, so the honest answer is
    // no body at all: answering either would publish one renderer's 409 for the exception the other
    // handles, and the 423 beside it would never be published for anything.
    $line = renderCallbackLine('return [function (OutOfStockException');
    expect($line)->toBeGreaterThan(0);

    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RenderCallbacks.php',
        '',
        '',
        line: $line,
    ));

    expect($analysis->returns)->toBe([])
        ->and(array_map(static fn ($d): string => $d->code, $analysis->diagnostics))
        ->toContain('inference.callable-not-found');
})->group('fixture');

/**
 * One arm of `PortalProblemRenderer`, which dispatches on `PortalException` plus a marker interface and
 * builds every body through one inherited `problem()` helper — the shape a class-level attribute cannot
 * separate, and where "the outermost declaring hop wins" is proved on real code rather than asserted.
 *
 * @return array{status: int|null, name: string|null, symbol: string|null, deps: list<string>}
 */
function portalArm(string $narrowType): array
{
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/PortalProblemRenderer.php',
        'App\\Exceptions\\PortalProblemRenderer',
        '__invoke',
        param: 'e',
        narrowType: 'App\\Exceptions\\'.$narrowType,
    ));

    expect($analysis->returns)->toHaveCount(1);
    $return = $analysis->returns[0];
    $status = $return->type instanceof ClassT ? ($return->type->typeArgs[1] ?? null) : null;

    return [
        'status' => $status instanceof LiteralT ? (int) $status->value : null,
        'name' => $return->component?->name,
        'symbol' => $return->component?->symbol,
        'deps' => $analysis->dependencyFiles,
    ];
}

it('names each arm of a one-family renderer after the render method that answered', function (
    string $narrowType,
    int $status,
    string $name,
    string $symbol,
): void {
    $arm = portalArm($narrowType);

    expect($arm['status'])->toBe($status)
        ->and($arm['name'])->toBe($name)
        ->and($arm['symbol'])->toBe('App\\Exceptions\\'.$symbol);
})->with([
    // Two arms name the body they answer with; the third declares nothing, so the house name on the
    // shared helper it builds through stands for it — one exception family, three names, no contest.
    'declaring arm' => ['PortalRejectedException', 422, 'PortalRejection', 'PortalProblemRenderer::renderRejection'],
    'the other declaring arm' => ['PortalThrottledException', 429, 'PortalThrottle', 'PortalProblemRenderer::renderThrottle'],
    'arm that declares nothing' => ['PortalUnavailableException', 503, 'PortalProblem', 'RendersProblems::problem'],
])->group('fixture');

it('records the file a name was written in, not just the one the render path names', function (): void {
    $arm = portalArm('PortalUnavailableException');

    // The declaration is on the inherited helper, in a file nothing in PortalProblemRenderer.php mentions.
    // A fragment keyed only on what the renderer names would serve the old name after the helper is edited.
    expect($arm['symbol'])->toBe('App\\Exceptions\\RendersProblems::problem');

    $names = array_map(static fn (string $file): string => basename($file), $arm['deps']);
    expect($names)->toContain('RendersProblems.php')->and($names)->toContain('PortalProblemRenderer.php');
})->group('fixture');

it('reads a marker-interface arm as one only a type that is BOTH reaches', function (): void {
    // `$e instanceof PortalException && $e instanceof HasRetryWindow` admits a type only if it is both. A
    // throttle IS a PortalException, so a guard read as "either" would answer with the earlier rejection
    // arm — its 422 body, under its name.
    $arm = portalArm('PortalThrottledException');

    expect($arm['status'])->toBe(429)->and($arm['name'])->toBe('PortalThrottle');
})->group('fixture');

it('takes the name off the analysed method itself for a renderable exception', function (): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/SubmissionLockedException.php',
        'App\\Exceptions\\SubmissionLockedException',
        'render',
    ));

    expect($analysis->returns)->toHaveCount(1);
    $component = $analysis->returns[0]->component;

    expect($component?->name)->toBe('SubmissionLocked')
        ->and($component?->symbol)->toBe('App\\Exceptions\\SubmissionLockedException::render')
        ->and(basename($component?->location->file ?? ''))->toBe('SubmissionLockedException.php');
})->group('fixture');

it('leaves a render path that declares nothing unnamed, exactly as before', function (): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/ProblemRenderer.php',
        'App\\Exceptions\\ProblemRenderer',
        'render',
        param: 'e',
        narrowType: 'Illuminate\\Validation\\ValidationException',
    ));

    expect($analysis->returns)->toHaveCount(1)
        ->and($analysis->returns[0]->component)->toBeNull();
})->group('fixture');

/**
 * One method of `GroupedProblemRenderer`, the `match (true)` renderer whose arms are read off the AST.
 *
 * @return array{status: int|null, title: string|null, name: string|null, symbol: string|null, deps: list<string>, codes: list<string>}
 */
function groupedArm(string $method, string $narrowType): array
{
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/GroupedProblemRenderer.php',
        'App\\Exceptions\\GroupedProblemRenderer',
        $method,
        param: 'e',
        narrowType: $narrowType,
    ));

    expect($analysis->returns)->toHaveCount(1);
    $return = $analysis->returns[0];
    $status = $return->type instanceof ClassT ? ($return->type->typeArgs[1] ?? null) : null;

    $title = null;
    $payload = $return->type instanceof ClassT ? ($return->type->typeArgs[0] ?? null) : null;
    foreach ($payload?->toArray()['fields'] ?? [] as $field) {
        if (($field['key'] ?? '') === 'title') {
            $title = $field['type']['value'] ?? null;
        }
    }

    return [
        'status' => $status instanceof LiteralT ? (int) $status->value : null,
        'title' => is_string($title) ? $title : null,
        'name' => $return->component?->name,
        'symbol' => $return->component?->symbol,
        'deps' => $analysis->dependencyFiles,
        'codes' => array_map(static fn ($d): string => $d->code, $analysis->diagnostics),
    ];
}

it('reads an arm listing several types as one ANY of them reaches', function (string $narrowType, int $status): void {
    // `match (true) { $e instanceof A, $e instanceof B => … }` fires for either. Folding the conditions as
    // requirements makes the arm reachable by nothing, so both types fall through to a later arm and are
    // documented with its body — silently, since the arm that really rendered them was filtered out before
    // anything could notice two of them matched.
    $arm = groupedArm('__invoke', $narrowType);

    expect($arm['status'])->toBe($status)
        ->and($arm['title'])->toBe($status === 422 ? 'Submission refused' : 'Server Error')
        // The arm names the type exactly and nothing else does, so there is nothing ambiguous to report.
        ->and($arm['codes'])->not->toContain('inference.ambiguous-narrowing');
})->with([
    'the type the arm lists first' => ['App\\Exceptions\\PortalRejectedException', 422],
    'the type it lists second' => ['App\\Exceptions\\PortalThrottledException', 422],
    'a type it lists nowhere' => ['RuntimeException', 500],
])->group('fixture');

it('widens an arm whose other side says nothing about the parameter', function (string $method, string $narrowType, int $status): void {
    // `$e instanceof A || $e->isFatal()` is reached by a fatal B, so the whole guard has to be broad —
    // `[]` on one side of an alternation is "anything", where on one side of a conjunction it is "no
    // constraint". Reading the second the first way is how an arm a type really reaches is ruled out.
    expect(groupedArm($method, $narrowType)['status'])->toBe($status);
})->with([
    'a type the `||` arm does not name' => ['renderFatal', 'RuntimeException', 503],
    'a type it does name' => ['renderFatal', 'App\\Exceptions\\PortalUnavailableException', 503],
    'the `&&` arm still requires both' => ['renderGated', 'RuntimeException', 500],
    'and admits what satisfies both' => ['renderGated', 'App\\Exceptions\\PortalUnavailableException', 503],
])->group('fixture');

it('names a body after the trait method that declared it, and keys the trait file either way', function (
    string $method,
    ?string $name,
    ?string $symbol,
): void {
    // A trait-imported method is reported as the USING class's own while its file stays the trait's. So the
    // symbol has to name the trait, or the reader is sent to a class whose file has no attribute in it —
    // and the trait's file is a dependency whether or not it declares anything today, or adding the
    // attribute to it leaves warm fragments valid and a warm build publishes a different name from a cold.
    $arm = groupedArm($method, 'RuntimeException');

    expect($arm['name'])->toBe($name)
        ->and($arm['symbol'])->toBe($symbol)
        ->and(array_map(static fn (string $file): string => basename($file), $arm['deps']))
        ->toContain('RendersGroupedProblems.php');
})->with([
    'a trait method that declares one' => ['renderNamed', 'GroupedProblem', 'App\\Exceptions\\RendersGroupedProblems::namedProblem'],
    'a trait method that declares none' => ['renderPlain', null, null],
])->group('fixture');
