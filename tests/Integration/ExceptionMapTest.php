<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine truth for an exception mapper (`$exceptions->map()`) read with `CallableRef::$returnsExceptions`:
 * every reachable return is the exception the handler renders instead, so each is read as the throw of what
 * it builds — its class and the status a `throw` of the same expression states — across the spellings an
 * application writes. A return handing the parameter back is no translation, and one naming no class is
 * said rather than skipped.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The mapper `ExceptionMappers::$method()` returns, analysed for a throw of `$thrown`. */
function mapperAnalysis(string $method, string $thrown): ActionAnalysis
{
    $file = 'app/Exceptions/ExceptionMappers.php';
    $source = explode("\n", (string) file_get_contents(FixtureRunner::path($file)));
    $declared = 0;
    foreach ($source as $index => $text) {
        if (str_contains($text, 'public function '.$method.'(')) {
            $declared = $index + 1;
        }
    }
    expect($declared)->toBeGreaterThan(0);

    // The closure starts on the line of its `return`, two below the method's declaration.
    return ActionAnalysis::fromArray(FixtureRunner::analyzeCallable(
        $file,
        '',
        '',
        line: $declared + 2,
        param: 'e',
        narrowType: $thrown,
        returnsExceptions: true,
    ));
}

/**
 * @return list<array{string, int|null}>
 */
function mapperTranslations(ActionAnalysis $analysis): array
{
    return array_map(static fn (ThrownException $t): array => [$t->exceptionFqcn, $t->httpStatusHint], $analysis->throws);
}

function mapperUnread(ActionAnalysis $analysis): bool
{
    return array_filter($analysis->returns, static fn ($site): bool => $site->returnsParameter === null && $site->type instanceof UnknownT) !== [];
}

it('reads the exception a mapper returns, at the status its construction states', function (string $method, string $thrown, array $expected): void {
    $analysis = mapperAnalysis($method, $thrown);

    expect(mapperTranslations($analysis))->toBe($expected)
        ->and(mapperUnread($analysis))->toBeFalse()
        ->and($analysis->dependencyFiles)->toContain(FixtureRunner::path('app/Exceptions/ExceptionMappers.php'));
})->with([
    // `new HttpException(409, …)` states 409 in its first argument, which is what the handler sends.
    'a literal status, arrow function' => ['literalStatus', 'App\\Exceptions\\OutOfStockException', [['Symfony\\Component\\HttpKernel\\Exception\\HttpException', 409]]],
    // A local assigned once is the construction it holds, as it is for a `throw $e`.
    'a construction one assignment back' => ['viaLocal', 'App\\Exceptions\\OutOfStockException', [['Symfony\\Component\\HttpKernel\\Exception\\HttpException', 402]]],
    // The factory builds a duplicate with the constructor's default, which the class documents as a 409.
    'a static factory on the class' => ['viaFactory', 'App\\Exceptions\\OrderConflictException', [['App\\Exceptions\\ExportConflictException', 409]]],
    // Symfony's class fixes its 409 in a vendor constructor no fold reads; the adapter's table places it.
    'a framework class that pins its own status' => ['frameworkClass', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException', [['Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException', null]]],
    // Nothing at the construction is a constant, so the status is unread rather than guessed.
    'a status chosen at run time' => ['dynamicStatus', 'App\\Exceptions\\OutOfStockException', [['Symfony\\Component\\HttpKernel\\Exception\\HttpException', null]]],
])->group('fixture');

it('reads every exception a mapper can answer with', function (): void {
    // Two branches, two exceptions the handler may render: both are answers, and neither is chosen.
    expect(mapperTranslations(mapperAnalysis('twoWays', 'App\\Exceptions\\OutOfStockException')))->toBe([
        ['Symfony\\Component\\HttpKernel\\Exception\\HttpException', 402],
        ['Illuminate\\Auth\\Access\\AuthorizationException', 403],
    ]);
})->group('fixture');

it('reads a return handing the parameter back as no translation', function (string $thrown, array $expected, bool $echoes): void {
    $analysis = mapperAnalysis('narrowed', $thrown);

    expect(mapperTranslations($analysis))->toBe($expected)
        ->and(array_filter($analysis->returns, static fn ($site): bool => $site->returnsParameter === 'e') !== [])->toBe($echoes)
        ->and(mapperUnread($analysis))->toBeFalse();
})->with([
    'the subclass it translates' => ['App\\Exceptions\\OutOfStockException', [['Symfony\\Component\\HttpKernel\\Exception\\HttpException', 410]], false],
    'any other class it is keyed on' => ['App\\Exceptions\\OrderConflictException', [], true],
])->group('fixture');

it('says so where a return names no exception class', function (): void {
    $analysis = mapperAnalysis('unnamed', 'App\\Exceptions\\OrderConflictException');

    expect(mapperUnread($analysis))->toBeTrue();
})->group('fixture');

it('reads an HttpResponseException a mapper returns as the response it carries', function (string $method, string $fqcn, string $error): void {
    // The handler sends the response an `HttpResponseException` carries as it is, so that response is
    // the answer, read as a `throw` of it would be: a 423 with the body written. The class alone states no
    // status, so it keeps the 500 it falls back to, which stands only where no response could be read.
    $analysis = mapperAnalysis($method, 'Illuminate\\Database\\Eloquent\\ModelNotFoundException');

    expect(mapperTranslations($analysis))->toBe([[$fqcn, 500]])
        ->and(mapperUnread($analysis))->toBeFalse()
        ->and($analysis->throws[0]->response?->type->toArray())->toBe([
            'kind' => 'class',
            'fqcn' => 'Illuminate\\Http\\JsonResponse',
            'typeArgs' => [
                ['kind' => 'arrayShape', 'isList' => false, 'fields' => [
                    ['key' => 'error', 'type' => ['kind' => 'literal', 'base' => 'string', 'value' => $error], 'optional' => false],
                ]],
                ['kind' => 'literal', 'base' => 'int', 'value' => 423],
            ],
        ]);
})->with([
    'the framework\'s own class' => ['carriedResponse', 'Illuminate\\Http\\Exceptions\\HttpResponseException', 'gone_elsewhere'],
    // A subclass is sent as whatever reaches the framework's constructor, here through its own factory.
    'a subclass built by its factory' => ['carriedBySubclass', 'App\\Exceptions\\Relayed\\ExportRefusedException', 'locked'],
])->group('fixture');
