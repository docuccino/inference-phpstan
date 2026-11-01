<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine proof that a closure route's action is analysed. The router reflects a closure to a
 * `{closure}` action at the line its keyword is written on, and the document owes that route the
 * responses its body sends and the errors it raises, exactly as it owes a controller method's.
 *
 * An arrow function is the same closure with one implicit return, so it owes the same answer: the fixture
 * routes file writes every response both ways, and each pair is held to one analysis.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The line of the `Route::get()` registering `$uri`, which is the line its closure's keyword is on. */
function closureRouteLine(string $uri): int
{
    $source = file_get_contents(FixtureRunner::path('app/Http/closure-routes.php'));
    foreach (explode("\n", $source === false ? '' : $source) as $index => $line) {
        if (str_contains($line, "Route::get('".$uri."'")) {
            return $index + 1;
        }
    }

    throw new RuntimeException("route '{$uri}' not found in the fixture routes file");
}

/**
 * Every route in the fixture routes file, analysed by one engine, by URI.
 *
 * @return array<string, array<string, mixed>>
 */
function closureRouteAnalyses(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $source = (string) file_get_contents(FixtureRunner::path('app/Http/closure-routes.php'));
    preg_match_all("/Route::get\\('([^']+)'/", $source, $matches);
    $lines = array_map(closureRouteLine(...), $matches[1]);
    $byLine = FixtureRunner::analyzeClosures('app/Http/closure-routes.php', $lines);

    $memo = [];
    foreach ($matches[1] as $index => $uri) {
        /** @var array<string, mixed> $analysis */
        $analysis = $byLine[$lines[$index]];
        $memo[$uri] = $analysis;
    }

    return $memo;
}

/**
 * An analysis without the lines it was written on, which are all a closure and its arrow twin differ by.
 *
 * @param  array<array-key, mixed>  $analysis
 * @return array<array-key, mixed>
 */
function closureRouteAnswer(array $analysis): array
{
    $answer = [];
    foreach ($analysis as $key => $value) {
        if ($key !== 'line') {
            $answer[$key] = is_array($value) ? closureRouteAnswer($value) : $value;
        }
    }

    return $answer;
}

it('reads a closure route for what it returns and what it throws', function (): void {
    $json = closureRouteAnalyses()['/closure/json'];
    $resource = closureRouteAnalyses()['/closure/resource/{id}'];

    expect($json['diagnostics'])->toBe([])
        ->and($json['returns'][0]['type']['fqcn'])->toBe('Illuminate\\Http\\JsonResponse')
        ->and(array_column($json['returns'][0]['type']['typeArgs'][0]['fields'], 'key'))->toBe(['id', 'name'])
        ->and($resource['returns'][0]['type']['fqcn'])->toBe('App\\Http\\Resources\\UserResource')
        ->and(array_map(static fn (array $t): string => $t['exceptionFqcn'].'@'.$t['httpStatusHint'], $resource['throws']))
        ->toBe(['Illuminate\\Database\\Eloquent\\ModelNotFoundException@404']);
})->group('fixture');

it('answers for an arrow function what the closure it means answers', function (string $closure, string $arrow): void {
    $analyses = closureRouteAnalyses();

    // Not two empty answers agreeing: the closure side recovers something real.
    expect(array_merge($analyses[$closure]['returns'], $analyses[$closure]['throws']))->not->toBeEmpty()
        ->and($analyses[$closure]['diagnostics'])->toBe([])
        ->and(closureRouteAnswer($analyses[$arrow]))->toBe(closureRouteAnswer($analyses[$closure]));
})->with([
    'a JSON response' => ['/closure/json', '/arrow/json'],
    'a resource, and the 404 its lookup throws' => ['/closure/resource/{id}', '/arrow/resource/{id}'],
    'a view' => ['/closure/view', '/arrow/view'],
    'an abort' => ['/closure/abort', '/arrow/abort'],
    'a variable from the routes file' => ['/closure/captured', '/arrow/captured'],
    'a static closure' => ['/closure/static', '/arrow/static'],
    'an arrow function nested inside' => ['/closure/nested', '/arrow/nested'],
    'a throw expression' => ['/closure/throws', '/arrow/throws'],
])->group('fixture');

it('answers for neither of two closures written on one line, and says why', function (): void {
    // Reflection gives a line and nothing finer, so the inner closure's answer is not the route's.
    $analysis = closureRouteAnalyses()['/arrow/one-line'];

    expect($analysis['returns'])->toBe([])
        ->and($analysis['throws'])->toBe([])
        ->and(array_column($analysis['diagnostics'], 'code'))->toBe(['inference.method-not-found'])
        ->and($analysis['diagnostics'][0]['message'])->toContain('declares more than one closure');
})->group('fixture');

it('answers an arrow function route alone as it does among the rest of its routes file', function (): void {
    // A warm build replays what a cold one computed beside other routes, so the answer for one route
    // cannot depend on what else the same engine was asked — bytes and diagnostics both.
    $line = closureRouteLine('/arrow/resource/{id}');
    $alone = FixtureRunner::analyzeClosures('app/Http/closure-routes.php', [$line])[$line];

    expect(json_encode($alone))->toBe(json_encode(closureRouteAnalyses()['/arrow/resource/{id}']));
})->group('fixture');

it('invalidates an arrow function route when its routes file or the resource it returns is edited', function (): void {
    /** @var list<string> $dependencies */
    $dependencies = closureRouteAnalyses()['/arrow/resource/{id}']['dependencyFiles'];

    expect(fragmentAcrossDependencyEdit($dependencies, 'app/Http/closure-routes.php'))->toBe(['warm' => true, 'staleAfterEdit' => true])
        ->and(fragmentAcrossDependencyEdit($dependencies, 'app/Http/Resources/UserResource.php'))->toBe(['warm' => true, 'staleAfterEdit' => true]);
})->group('fixture');
