<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Which of an action's throws become API errors at all, against the real engine: abort status
 * folding, registry enrichment + rescue, bounded descent, `@throws` trust and catch subtraction.
 * What STATUS the surfaced error then carries is {@see ThrowStatusTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('surfaces exactly the expected API errors', function (string $method, array $expected): void {
    sort($expected);

    expect(signalThrows($method))->toBe($expected);
})->with([
    'abort + abort_if, both statuses folded' => ['abortAction', ['HttpException@403', 'HttpException@404']],
    // The same two calls with the status named rather than counted. PHPStan hands throw points the
    // NORMALIZED call, so a named argument already sits in the position the registry indexes — pinned
    // here because the day that stops being true, both statuses vanish without a word.
    'abort + abort_if, statuses named' => ['namedAbortAction', ['HttpException@418', 'HttpException@451']],
    'authorize → 403' => ['authorizeAction', ['AuthorizationException@403']],
    'static findOrFail rescued → 404' => ['findOrFailAction', ['ModelNotFoundException@404']],
    'inline validate → 422' => ['validateAction', ['ValidationException@422']],
    '2-deep descent, no @throws' => ['deepUndeclared', ['OutOfStockException@500', 'RuntimeException@500']],
    '@throws trusted, deeper hidden' => ['deepDeclared', ['OutOfStockException@500']],
    'vendor any-throwable = no API error' => ['anyThrowableNoise', []],
    'caught subtracted, escaping surfaced' => ['tryCatch', ['RuntimeException@500']],
    // The registry is keyed on a bare method name, so an app's own validate() is exactly where a guess
    // could overrule a truth: the callee is project code we read, so its own exception stands and no
    // ValidationException/422 is invented for it.
    "the app's own validate() keeps its own exception" => ['projectValidate', ['OutOfStockException@500']],
])->group('fixture');
