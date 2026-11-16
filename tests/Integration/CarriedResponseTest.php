<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Laravel renders an `HttpResponseException` by sending the response it carries as it is, and `abort()` handed
 * a response throws one, so the server answers with that response and not with an error it renders. Which
 * responses a request can get is still decided by where the exception goes: one a `catch` takes never leaves,
 * and one a `catch` for some other class lets pass does.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, ActionAnalysis> */
function carriedAnalyses(): array
{
    static $analyses = null;

    if ($analyses === null) {
        $analyses = [];
        $raw = FixtureRunner::analyzeMany(
            'app/Http/Controllers/CarriedResponseController.php',
            'App\\Http\\Controllers\\CarriedResponseController',
            ['helperJson', 'caught', 'abortCaughtAsHttp', 'abortStatus'],
        );
        foreach ($raw as $method => $analysis) {
            /** @var array<string, mixed> $analysis */
            $analyses[(string) $method] = ActionAnalysis::fromArray($analysis);
        }
    }

    return $analyses;
}

/** @return list<string> each return's status, as the type carries it */
function carriedStatuses(string $method): array
{
    return array_map(static function (ReturnSite $site): string {
        $status = $site->type->toArray()['typeArgs'][1] ?? null;

        return is_array($status) ? (string) json_encode($status['value'] ?? null) : 'none';
    }, carriedAnalyses()[$method]->returns);
}

/** The line of the first `throw` written in one action of the fixture controller. */
function carriedThrowLine(string $method): int
{
    $lines = explode("\n", (string) file_get_contents(FixtureRunner::path('app/Http/Controllers/CarriedResponseController.php')));
    $in = false;
    foreach ($lines as $index => $line) {
        $in = $in || str_contains($line, 'function '.$method.'(');
        if ($in && str_contains($line, 'throw ')) {
            return $index + 1;
        }
    }

    return 0;
}

/** @return list<string> */
function carriedThrows(string $method): array
{
    return array_map(static fn (ThrownException $throw): string => $throw->identityKey(), carriedAnalyses()[$method]->throws);
}

it('reads the carried response where the throw is written, and reports no exception for it', function (): void {
    expect(carriedStatuses('helperJson'))->toBe(['423'])
        ->and(carriedThrows('helperJson'))->toBe([])
        // Where the response is written is the throw, which is what an author is pointed at.
        ->and(carriedAnalyses()['helperJson']->returns[0]->location->line)->toBe(carriedThrowLine('helperJson'));
})->group('fixture');

it('drops a carried response a catch takes before it leaves the action', function (): void {
    // Only the response the catch answers with reaches the client.
    expect(carriedStatuses('caught'))->toBe(['200'])
        ->and(carriedThrows('caught'))->toBe([]);
})->group('fixture');

it('keeps a response handed to abort() past a catch for HttpException, which it is not', function (): void {
    // `abort()` handed a response throws an HttpResponseException, and that is no HttpException.
    expect(carriedStatuses('abortCaughtAsHttp'))->toBe(['200', '423'])
        ->and(carriedThrows('abortCaughtAsHttp'))->toBe([]);
})->group('fixture');

it('still reads abort() handed a status as the HttpException it throws', function (): void {
    expect(carriedAnalyses()['abortStatus']->returns)->toBe([])
        ->and(carriedThrows('abortStatus'))->toBe(['Symfony\\Component\\HttpKernel\\Exception\\HttpException@423']);
})->group('fixture');
