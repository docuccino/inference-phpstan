<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\StatusTextMarkerT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\TypeCondition;
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

it('proves the class a guard on the rendered response tests, however the guard is spelled', function (string $method, string $class, bool $rewriteFirst, string $echoed): void {
    $facts = array_map(static fn (ReturnSite $site): array => [
        'type' => $site->type instanceof ClassT ? $site->type->fqcn : $site->type->kind(),
        'echoes' => $site->returnsParameter,
        'typeConditions' => array_map(static fn (TypeCondition $c): array => $c->toArray(), $site->typeConditions),
    ], respondReturns($method, 'Illuminate\\Auth\\AuthenticationException'));

    $test = static fn (bool $holds): array => [['parameter' => 'response', 'class' => $class, 'value' => $holds]];
    $rewrite = ['type' => 'Illuminate\\Http\\JsonResponse', 'echoes' => null, 'typeConditions' => $test($class === 'Illuminate\\Http\\JsonResponse')];
    $echo = ['type' => $echoed, 'echoes' => 'response', 'typeConditions' => $test($class !== 'Illuminate\\Http\\JsonResponse')];

    // Source order: the early return's pass-through comes first, a ternary's and a turned-around guard's last.
    expect($facts)->toBe($rewriteFirst ? [$rewrite, $echo] : [$echo, $rewrite]);
})->with([
    'a negated guard returning early' => ['jsonGuarded', 'Illuminate\\Http\\JsonResponse', false, 'Symfony\\Component\\HttpFoundation\\Response'],
    'the guard turned around' => ['jsonGuardedReversed', 'Illuminate\\Http\\JsonResponse', true, 'Symfony\\Component\\HttpFoundation\\Response'],
    'the guard as a ternary' => ['jsonGuardedTernary', 'Illuminate\\Http\\JsonResponse', true, 'Symfony\\Component\\HttpFoundation\\Response'],
    // Handed back where it IS the class tested, so its type is that class.
    'a parenthesised negation of another class' => ['redirectGuarded', 'Illuminate\\Http\\RedirectResponse', true, 'Illuminate\\Http\\RedirectResponse'],
])->group('fixture');

it('records no class fact about a response the callback rebinds before it returns', function (): void {
    $sites = respondReturns('jsonRebound', 'Illuminate\\Auth\\AuthenticationException');

    // At the return the variable holds the rebuilt response or the one it was handed, and the guard reads
    // the first as not a JsonResponse: a fact about the variable, not about what the callback was handed.
    expect($sites)->toHaveCount(1)
        ->and($sites[0]->returnsParameter)->toBeNull()
        ->and($sites[0]->typeConditions)->toBe([]);
})->group('fixture');

it('records no class fact where the callback tests none', function (string $method): void {
    foreach (respondReturns($method, 'Illuminate\\Auth\\AuthenticationException') as $site) {
        expect($site->typeConditions)->toBe([]);
    }
})->with(['pathGated', 'earlyReturn', 'everywhere'])->group('fixture');

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

it('reads a return reached after the exception is swapped as reachable by every type, the swapped one too', function (string $thrown, bool $every): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RenderCallbacks.php',
        '',
        '',
        line: exceptionsFixtureLine('RenderCallbacks.php', 'function conflictRebound(') + 2,
        param: 'e',
        narrowType: $thrown,
        every: $every,
    ));

    // At the return the variable holds the generic error or an exception that is not a conflict. Read as
    // the type the callback was handed, that made the one return unreachable for the very type it swaps.
    expect($analysis->returns)->toHaveCount(1)
        ->and(($analysis->returns[0]->type instanceof ClassT ? ($analysis->returns[0]->type->typeArgs[1] ?? null) : null)?->toArray())
        ->toBe(['kind' => 'literal', 'base' => 'int', 'value' => 409]);
})->with([
    'the type it swaps' => ['App\\Exceptions\\OrderConflictException'],
    'any other type' => ['App\\Exceptions\\OutOfStockException'],
])->with([
    'the one answer chosen' => [false],
    'every answer reached' => [true],
])->group('fixture');

/** The kind of each member of a body as the engine recovered it, with the `??` a reason phrase carries. */
function echoedMembers(DType $body): array
{
    $members = [];
    foreach ($body instanceof ArrayShapeT ? $body->fields : [] as $field) {
        $members[(string) $field->key] = $field->type instanceof StatusTextMarkerT
            ? [$field->type->kind(), $field->type->type->kind(), $field->type->fallback?->value]
            : $field->type->kind();
    }

    return $members;
}

it('reads a member as the reason phrase of the status the response is sent with, however the key reaches it', function (string $method, array $expected): void {
    $sites = array_values(array_filter(
        respondReturns($method, 'Illuminate\\Validation\\ValidationException'),
        static fn (ReturnSite $site): bool => $site->type instanceof ClassT && $site->type->fqcn === 'Illuminate\\Http\\JsonResponse',
    ));
    expect($sites)->toHaveCount(1);

    $type = $sites[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and(echoedMembers($type->typeArgs[0]))->toBe($expected);
})->with([
    // Through the helper, where the status is named in a local before both the body and the response read it.
    'a local both read' => ['everywhere', ['type' => 'literal', 'title' => ['statusText', 'scalar', 'Error'], 'status' => 'statusMarker', 'errors' => 'list']],
    'read inline, through a class inheriting the table' => ['statusTextInline', ['title' => ['statusText', 'scalar', null], 'status' => 'statusMarker']],
    // The phrase of a code nothing says is the one sent: the member is the string it was read as.
    'a key that is not the status' => ['statusTextOtherKey', ['title' => 'scalar', 'status' => 'statusMarker']],
    // `->setStatusCode(500)` replaced the status both members read.
    'a status restated after the body' => ['statusTextRestated', ['title' => 'scalar', 'status' => 'scalar']],
])->group('fixture');

it('reads the members an object body built in place echoes of the status it is sent with', function (): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RespondCallbacks.php',
        '',
        '',
        line: exceptionsFixtureLine('RespondCallbacks.php', 'public function problemObject(') + 2,
        param: 'e',
        narrowType: 'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
        every: true,
    ));
    $sites = array_values(array_filter(
        $analysis->returns,
        static fn (ReturnSite $site): bool => $site->type instanceof ClassT && $site->type->fqcn === 'Illuminate\\Http\\JsonResponse',
    ));
    expect($sites)->toHaveCount(1);

    $type = $sites[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->typeArgs[0] ?? null)->toEqual(new ClassT('App\\Problems\\HttpProblem'))
        ->and($type->typeArgs[2] ?? null)->toEqual(new LiteralT('application/problem+json'))
        ->and(echoedMembers($type->typeArgs[3] ?? new ArrayShapeT([])))->toBe(['status' => 'statusMarker', 'title' => ['statusText', 'scalar', 'Error']])
        // What the members were read out of keys the fragment: the class's constructor.
        ->and(array_map(basename(...), $analysis->dependencyFiles))->toContain('HttpProblem.php');
})->group('fixture');

// A value named before the response's status is replaced is the old status, not the one sent: no member of
// the body may echo the status it goes out with, however it was named.
it('reads no echo of the status sent off a value named before that status was replaced', function (string $method, int $slot, array $expected): void {
    $sites = array_values(array_filter(
        respondReturns($method),
        static fn (ReturnSite $site): bool => $site->type instanceof ClassT && $site->type->fqcn === 'Illuminate\\Http\\JsonResponse',
    ));
    expect($sites)->toHaveCount(1);

    $type = $sites[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and(echoedMembers($type->typeArgs[$slot] ?? new ArrayShapeT([])))->toBe($expected);
})->with([
    'a local the body reads' => ['statusTextStale', 0, ['title' => 'scalar', 'status' => 'scalar']],
    'a body built in a local' => ['statusStaleBody', 0, ['status' => 'scalar']],
    'an object built in a local' => ['problemStale', 3, []],
])->group('fixture');

it('keys a phrase read through an application class by that class, whose hierarchy decides it is the table', function (): void {
    $analysis = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        'app/Exceptions/RespondCallbacks.php',
        '',
        '',
        line: exceptionsFixtureLine('RespondCallbacks.php', 'public function statusTextThroughApp(') + 2,
        every: true,
    ));
    $sites = array_values(array_filter(
        $analysis->returns,
        static fn (ReturnSite $site): bool => $site->type instanceof ClassT && $site->type->fqcn === 'Illuminate\\Http\\JsonResponse',
    ));
    expect($sites)->toHaveCount(1);

    $type = $sites[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and(echoedMembers($type->typeArgs[0]))->toBe(['title' => ['statusText', 'scalar', 'Error']])
        // Redeclaring `$statusTexts` there would make it another table: a warm build must notice.
        ->and(array_map(basename(...), $analysis->dependencyFiles))->toContain('ApiResponse.php');
})->group('fixture');
