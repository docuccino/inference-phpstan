<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The throw-analysis scorecard against the real engine: abort status folding, registry
 * enrichment + rescue, bounded descent, `@throws` trust, catch subtraction, and
 * exception identity by (fqcn, status).
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return list<string> signal-disposition exceptions as "ShortName@status"
 */
function signalThrows(string $method): array
{
    $analysis = FixtureRunner::analyze(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        $method,
    );

    $out = [];
    /** @var list<array<string, mixed>> $throws */
    $throws = $analysis['throws'];
    foreach ($throws as $throw) {
        if (($throw['disposition'] ?? null) !== 'signal') {
            continue;
        }
        $fqcn = (string) $throw['exceptionFqcn'];
        $pos = strrpos($fqcn, '\\');
        $short = $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
        $out[] = $short.'@'.($throw['httpStatusHint'] ?? 'null');
    }
    sort($out);

    return $out;
}

dataset('throw cases', [
    'abort + abort_if, both statuses folded' => ['abortAction', ['HttpException@403', 'HttpException@404']],
    'authorize → 403' => ['authorizeAction', ['AuthorizationException@403']],
    'static findOrFail rescued → 404' => ['findOrFailAction', ['ModelNotFoundException@404']],
    'inline validate → 422' => ['validateAction', ['ValidationException@422']],
    '2-deep descent, no @throws' => ['deepUndeclared', ['OutOfStockException@500', 'RuntimeException@500']],
    '@throws trusted, deeper hidden' => ['deepDeclared', ['OutOfStockException@500']],
    'vendor any-throwable = no API error' => ['anyThrowableNoise', []],
    'caught subtracted, escaping surfaced' => ['tryCatch', ['RuntimeException@500']],
]);

it('surfaces exactly the expected API errors', function (string $method, array $expected): void {
    sort($expected);

    expect(signalThrows($method))->toBe($expected);
})->with('throw cases')->group('fixture');
