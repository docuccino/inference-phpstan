<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A guard helper that refuses the request by throwing is the same helper whether it returns `never` or `void`:
 * the framework answers with whatever it throws either way, so the request can get exactly the same responses.
 * The analyser marks a call to a `never` callee that declares nothing as throwing a bare `Throwable`, which
 * names no error at all; what the helper's body throws is what the request gets, as it is for the `void` one.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> every guard action outside the throw corpus, by name */
function neverGuardActions(): array
{
    static $analyses = null;

    if ($analyses === null) {
        $file = (string) file_get_contents(FixtureRunner::path('app/Http/Controllers/NeverGuardController.php'));
        preg_match_all('/public function (\w+)\(/', $file, $found);

        /** @var array<string, array<string, mixed>> $analyses */
        $analyses = FixtureRunner::analyzeMany(
            'app/Http/Controllers/NeverGuardController.php',
            'App\\Http\\Controllers\\NeverGuardController',
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
function guardFacts(array $analysis): array
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

it('documents what a never guard throws, as its void twin does', function (string $method, array $expected): void {
    sort($expected);

    expect(reportedThrows($method))->toBe($expected)
        ->and(reportedThrows(str_replace('never', 'void', $method)))->toBe($expected);
})->with([
    'an HttpException, private' => ['neverHelperHttp', ['HttpException@409']],
    'an HttpException, static' => ['neverStaticHelperHttp', ['HttpException@409']],
    'abort(), private' => ['neverHelperAbort', ['HttpException@404']],
    'abort(), static' => ['neverStaticHelperAbort', ['HttpException@404']],
    'a status written at the throw, private' => ['neverHelperCustom', ['ExportLockedException@423']],
    'a status written at the throw, static' => ['neverStaticHelperCustom', ['ExportLockedException@423']],
    'a ValidationException, private' => ['neverHelperValidation', ['ValidationException@422']],
    'a ValidationException, static' => ['neverStaticHelperValidation', ['ValidationException@422']],
    // Each branch is a request the helper refuses, so each is a response.
    'one of two errors, by branch' => ['neverHelperEither', ['ExportLockedException@423', 'HttpException@410']],
])->group('fixture');

it('documents what a never guard throws however it says it never returns', function (string $method): void {
    expect(reportedThrows($method))->toBe(['ExportLockedException@423']);
})->with([
    // A never helper that calls another refuses with what that one throws.
    'through another never helper' => ['neverHelperNested'],
    // A declared `@throws` is still what the call raises.
    'declaring what it throws' => ['neverHelperDeclared'],
    'by `@return never`' => ['neverHelperDocblock'],
    'by `@phpstan-return never`' => ['neverHelperPhpstanDocblock'],
    'by `@return noreturn`' => ['neverHelperNoreturn'],
])->group('fixture');

it('drops what a catch around a never guard takes, and keeps what it lets out', function (): void {
    expect(reportedThrows('neverHelperCaught'))->toBe(['HttpException@410']);
})->group('fixture');

it('documents a guard written outside the controller as its void twin, wherever it is written', function (): void {
    $pairs = 0;
    foreach (neverGuardActions() as $method => $analysis) {
        $twin = str_replace('Never', 'Void', $method);
        if ($twin === $method) {
            continue;
        }

        expect(guardFacts($analysis))->toBe(guardFacts(neverGuardActions()[$twin]), $method);
        $pairs++;
    }

    // Five ways to refuse from a trait, a service and a function, the form request, two carried responses,
    // and a trait method declaring it may throw anything.
    expect($pairs)->toBe(19);
})->group('fixture');

it('documents what a never guard written outside the controller refuses with', function (string $method, array $throws, array $returns): void {
    expect(guardFacts(neverGuardActions()[$method]))->toBe(['throws' => $throws, 'returns' => ['200', ...$returns]]);
})->with([
    'a trait method' => ['traitNeverCustom', ['ExportLockedException@423'], []],
    'an injected service' => ['serviceNeverCustom', ['ExportLockedException@423'], []],
    'the form request' => ['formRequestNever', ['ExportLockedException@423'], []],
    'a trait method, abort()' => ['traitNeverAbort', ['HttpException@404'], []],
    'an injected service, a ValidationException' => ['serviceNeverValidation', ['ValidationException@422'], []],
    // A function's body runs at the call as a method's does, so it refuses with what that body throws.
    'a function' => ['functionNeverCustom', ['ExportLockedException@423'], []],
    'a function, abort()' => ['functionNeverAbort', ['HttpException@404'], []],
    // A response thrown for the framework to send is the response the request is answered with.
    'a carried response, private' => ['privateNeverCarried', [], ['429']],
    'a carried response, static' => ['staticNeverCarried', [], ['429']],
    'a carried response, trait' => ['traitNeverCarried', [], ['429']],
    'a carried response, service' => ['serviceNeverCarried', [], ['429']],
    'a carried response, function' => ['functionNeverCarried', [], ['429']],
])->group('fixture');

it('keeps a never guard declaring it may throw anything to its declaration', function (): void {
    // A `@throws` is what the call raises, and a callee declaring one is not descended for what it hides,
    // as one returning `void` is not: `@throws \Throwable` names no error the document can carry.
    expect(guardFacts(neverGuardActions()['traitNeverAnything']))->toBe(['throws' => [], 'returns' => ['200']]);
})->group('fixture');

it('depends on the file a never guard is written in', function (string $method, string $file): void {
    // The helper's body is what the response is read from, so editing it has to reach a warm build.
    /** @var list<string> $files */
    $files = neverGuardActions()[$method]['dependencyFiles'];

    expect(array_map('basename', $files))->toContain($file);
})->with([
    'a trait' => ['traitNeverCustom', 'RefusesRequests.php'],
    'a service' => ['serviceNeverCustom', 'RequestGuard.php'],
    'a form request' => ['formRequestNever', 'RefusableRequest.php'],
    'a function' => ['functionNeverCustom', 'helpers.php'],
])->group('fixture');
