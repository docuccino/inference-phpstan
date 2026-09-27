<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine truth for a `$exceptions->respond()` callback read as a post-processor: every return the
 * narrowed exception can reach, the parameter a return hands back unchanged, and the literal-argument
 * calls PHPStan's own narrowing proves at each return — across the spellings applications write (an
 * arrow function with a ternary, an early return, a branch on the exception, one on the rendered
 * status). The same body reading also finds render callbacks written as arrow functions, which analysing
 * by file+line used to miss outright.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The 1-based line of the first line in `$file` (under `app/Exceptions/`) containing `$needle`. */
function exceptionsFixtureLine(string $file, string $needle): int
{
    $source = (string) file_get_contents(FixtureRunner::path('app/Exceptions/'.$file));
    foreach (explode("\n", $source) as $index => $text) {
        if (str_contains($text, $needle)) {
            return $index + 1;
        }
    }

    return 0;
}

/**
 * Every return of the `RespondCallbacks` closure whose method is `$method`, for one thrown type.
 *
 * @return list<ReturnSite>
 */
function respondReturns(string $method, ?string $thrown = null): array
{
    $line = exceptionsFixtureLine('RespondCallbacks.php', 'public function '.$method.'(') + 2;
    expect($line)->toBeGreaterThan(2);

    return ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RespondCallbacks.php',
        '',
        '',
        line: $line,
        param: $thrown === null ? '' : 'e',
        narrowType: $thrown ?? '',
        every: true,
    ))->returns;
}

/**
 * One return as the three facts the finalizer reads off it.
 *
 * @return array{type: string, echoes: string|null, conditions: list<array<string, mixed>>}
 */
function respondFacts(ReturnSite $site): array
{
    return [
        'type' => $site->type instanceof ClassT ? $site->type->fqcn : $site->type->kind(),
        'echoes' => $site->returnsParameter,
        'conditions' => array_map(static fn (CallCondition $c): array => $c->toArray(), $site->conditions),
    ];
}

it('reads both branches of an arrow-function ternary on the request path, each with the fact that reaches it', function (string $method): void {
    $facts = array_map(respondFacts(...), respondReturns($method, 'Illuminate\\Auth\\AuthenticationException'));

    $gate = static fn (bool $holds): array => [['parameter' => 'request', 'method' => 'is', 'arguments' => ['api/*'], 'value' => $holds]];

    // Source order, which for the early return puts the pass-through first: both are answers here.
    $expected = [
        ['type' => 'Illuminate\\Http\\JsonResponse', 'echoes' => null, 'conditions' => $gate(true)],
        ['type' => 'Symfony\\Component\\HttpFoundation\\Response', 'echoes' => 'response', 'conditions' => $gate(false)],
    ];

    expect($facts)->toBe($method === 'earlyReturn' ? array_reverse($expected) : $expected);
})->with(['pathGated', 'earlyReturn'])->group('fixture');

it('recovers the rewrite the helper builds from the rendered response: problem+json, a status it read back', function (): void {
    $sites = respondReturns('everywhere', 'Illuminate\\Validation\\ValidationException');
    expect($sites)->toHaveCount(1);

    $type = $sites[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        // Read off the incoming response, so no number folds — the finalizer files it where it was rendered.
        ->and($type->typeArgs[1] ?? null)->not->toBeInstanceOf(LiteralT::class)
        ->and($type->typeArgs[2] ?? null)->toEqual(new LiteralT('application/problem+json'));

    $fields = [];
    foreach ($type->typeArgs[0]->toArray()['fields'] ?? [] as $field) {
        $fields[$field['key']] = $field['optional'];
    }

    // `errors` is added only for a validation failure, and the helper is not analysed per thrown type, so
    // it is a member the body MAY carry: optional, never required of a 401.
    expect($fields)->toBe(['type' => false, 'title' => false, 'status' => false, 'errors' => true]);
})->group('fixture');

it('keeps only the returns the thrown type can reach, where the callback branches on the exception', function (string $thrown, array $facts): void {
    expect(array_map(static fn (array $f): array => [$f['type'], $f['echoes']], array_map(respondFacts(...), respondReturns('perException', $thrown))))
        ->toBe($facts);
})->with([
    // A validation failure never reaches the pass-through: the branch before it returned for exactly that.
    'the type the branch tests' => ['Illuminate\\Validation\\ValidationException', [['Illuminate\\Http\\JsonResponse', null]]],
    'any other type' => ['Illuminate\\Auth\\AuthenticationException', [['Symfony\\Component\\HttpFoundation\\Response', 'response']]],
])->group('fixture');

it('proves the rendered status a branch on it is reached at', function (string $method): void {
    $facts = array_map(respondFacts(...), respondReturns($method));

    expect($facts)->toBe([
        ['type' => 'Illuminate\\Http\\RedirectResponse', 'echoes' => null, 'conditions' => [['parameter' => 'response', 'method' => 'getStatusCode', 'arguments' => [], 'value' => 419]]],
        ['type' => 'Symfony\\Component\\HttpFoundation\\Response', 'echoes' => 'response', 'conditions' => []],
    ]);
})->with([
    'spelled as an early return' => ['statusGated'],
    // No exception parameter, so nothing is narrowed — and the ternary is still two answers, not one.
    'spelled as a ternary' => ['statusTernary'],
])->group('fixture');

it('reads a pass-through that only adds a header as unchanged, and one that retypes the body as not', function (string $method, ?string $echoes): void {
    $sites = respondReturns($method);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->returnsParameter)->toBe($echoes);
})->with([
    'a header of its own' => ['passThrough', 'response'],
    'the media type rewritten' => ['retyped', null],
    // The helper sets the media type and the body on the very object returned, so it is not unchanged.
    'handed to a helper that rewrites it' => ['decoratedInPlace', null],
])->group('fixture');

it('reads the response a catch hands back after a helper that threw as not the one it was handed', function (): void {
    $facts = array_map(respondFacts(...), respondReturns('reshapedElseHandedBack', 'Illuminate\\Auth\\AuthenticationException'));

    // The helper had the response before it threw, so what the catch returns may carry its writes.
    expect(array_map(static fn (array $f): array => [$f['type'], $f['echoes']], $facts))->toBe([
        ['Illuminate\\Http\\JsonResponse', null],
        ['Symfony\\Component\\HttpFoundation\\Response', null],
    ]);
})->group('fixture');

it('finds a render callback written as an arrow function', function (): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RenderCallbacks.php',
        '',
        '',
        line: exceptionsFixtureLine('RenderCallbacks.php', 'return fn (OutOfStockException $e)'),
        param: 'e',
        narrowType: 'App\\Exceptions\\OutOfStockException',
    ));

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and(($type->typeArgs[1] ?? null)?->value)->toBe(409);
})->group('fixture');

it('reads each branch of a ternary render callback as the answer for the types that reach it', function (string $thrown, string $kind): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RenderCallbacks.php',
        '',
        '',
        line: exceptionsFixtureLine('RenderCallbacks.php', 'return fn (\\Throwable $e)'),
        param: 'e',
        narrowType: $thrown,
    ));

    expect($analysis->returns)->toHaveCount(1)
        ->and($analysis->returns[0]->type instanceof ClassT ? $analysis->returns[0]->type->fqcn : $analysis->returns[0]->type::class)->toBe($kind);
})->with([
    // Collapsed to the one type both branches share, this was a nullable response that is neither.
    'the tested type' => ['App\\Exceptions\\OrderConflictException', 'Illuminate\\Http\\JsonResponse'],
    'any other type, handed back to the framework' => ['App\\Exceptions\\OutOfStockException', NullT::class],
])->group('fixture');
