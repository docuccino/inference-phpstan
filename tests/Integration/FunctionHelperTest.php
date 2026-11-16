<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A helper an application writes as a function is the same helper written as a method: PHP runs its body at
 * the call either way, so the request gets whatever that body throws or returns. Global functions from the
 * application's own `helpers.php`, declared inside `if (! function_exists(…))`, and namespaced ones alike.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> every action of the controller, by name */
function functionHelperActions(): array
{
    static $analyses = null;

    if ($analyses === null) {
        $file = (string) file_get_contents(FixtureRunner::path('app/Http/Controllers/FunctionHelperController.php'));
        preg_match_all('/public function (\w+)\(/', $file, $found);

        /** @var array<string, array<string, mixed>> $analyses */
        $analyses = FixtureRunner::analyzeMany(
            'app/Http/Controllers/FunctionHelperController.php',
            'App\\Http\\Controllers\\FunctionHelperController',
            $found[1],
        );
    }

    return $analyses;
}

/**
 * What reaches the document for one analysis: each error it publishes, and each response's status.
 *
 * @param  array<string, mixed>  $analysis
 * @return array{throws: list<string>, returns: list<string>}
 */
function functionHelperFacts(array $analysis): array
{
    $throws = [];
    /** @var list<array<string, mixed>> $thrown */
    $thrown = $analysis['throws'];
    foreach ($thrown as $throw) {
        if (($throw['disposition'] ?? null) === 'signal') {
            $fqcn = (string) $throw['exceptionFqcn'];
            $throws[] = substr($fqcn, (int) strrpos($fqcn, '\\') + 1).'@'.($throw['httpStatusHint'] ?? 'null');
        }
    }
    sort($throws);

    $returns = [];
    /** @var list<array{type: array{typeArgs?: list<array{value?: mixed}>}}> $sites */
    $sites = $analysis['returns'];
    foreach ($sites as $site) {
        $returns[] = (string) json_encode($site['type']['typeArgs'][1]['value'] ?? null);
    }

    return ['throws' => $throws, 'returns' => $returns];
}

it('documents what a helper function refuses or answers with', function (string $method, array $throws, array $returns): void {
    expect(functionHelperFacts(functionHelperActions()[$method]))->toBe(['throws' => $throws, 'returns' => $returns]);
})->with([
    'a global function, an HttpException' => ['globalHttp', ['HttpException@409'], ['200']],
    'a global function, abort()' => ['globalAbort', ['HttpException@404'], ['200']],
    'a global function, a status written at the throw' => ['globalCustom', ['ExportLockedException@423'], ['200']],
    'a global function, a ValidationException' => ['globalValidation', ['ValidationException@422'], ['200']],
    // A response thrown for the framework to send is the response the request is answered with.
    'a global function, a carried response' => ['globalCarried', [], ['200', '429']],
    // A response the function builds is the response, not a status nobody read.
    'a global function returning a response' => ['globalGone', [], ['410']],
    // The parameter may be any case; the call names one, and its backing value is what the server sends.
    'a global function handed an enum case' => ['globalFault', [], ['410']],
    // A guard that calls another refuses with what that one throws.
    'a namespaced function calling another' => ['namespacedNested', ['ExportLockedException@423'], ['200']],
    'a namespaced function returning a response' => ['namespacedGone', [], ['410']],
    'a namespaced function handed an enum case' => ['namespacedFault', [], ['423']],
    // The catch took the 409 and handed it to a function that only logs its message, so it goes no further.
    'a catch handing what it took to a function' => ['caughtNotedByFunction', [], ['200']],
])->group('fixture');

it('documents a helper function as the same body written as a method', function (string $function, string $method): void {
    $actions = functionHelperActions();

    expect(functionHelperFacts($actions[$function]))->toBe(functionHelperFacts($actions[$method]))
        // The body read is the same body, so the response carries the same shape, not only the same status.
        ->and(json_encode($actions[$function]['returns'][0]['type'] ?? null))->toBe(json_encode($actions[$method]['returns'][0]['type'] ?? null));
})->with([
    'an HttpException' => ['globalHttp', 'methodHttp'],
    'a carried response' => ['globalCarried', 'methodCarried'],
    'a response, global' => ['globalGone', 'methodGone'],
    'a response, namespaced' => ['namespacedGone', 'methodGone'],
    'an enum case' => ['namespacedFault', 'methodFault'],
    'a catch handing what it took on' => ['caughtNotedByFunction', 'caughtNotedByMethod'],
])->group('fixture');

it('depends on the file a helper function is written in', function (string $method, string $file): void {
    // The function's body is what the response is read from, so editing it has to reach a warm build.
    /** @var list<string> $files */
    $files = functionHelperActions()[$method]['dependencyFiles'];

    expect(array_map(static fn (string $path): string => basename(dirname($path)).'/'.basename($path), $files))->toContain($file);
})->with([
    'a global guard' => ['globalHttp', 'app/helpers.php'],
    'a global carried response' => ['globalCarried', 'app/helpers.php'],
    'a global response' => ['globalGone', 'app/helpers.php'],
    'a global response handed a case' => ['globalFault', 'app/helpers.php'],
    'a namespaced guard calling another' => ['namespacedNested', 'Support/helpers.php'],
    'a namespaced response' => ['namespacedGone', 'Support/helpers.php'],
    'a namespaced response handed a case' => ['namespacedFault', 'Support/helpers.php'],
    // Whether the catch takes the exception is decided by what the function reads off it.
    'a function a catch hands its exception to' => ['caughtNotedByFunction', 'Support/helpers.php'],
])->group('fixture');
