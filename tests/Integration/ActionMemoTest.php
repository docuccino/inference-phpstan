<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The engine's per-action memo. One engine serves every document in a build, and every version document
 * asks the identical action sequence, so an action asked for N times must be analysed once — and the memo
 * only pays for itself if it changes nothing that is emitted: the repeated answer has to be the answer a
 * cold single-ask process gives, diagnostics included.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('analyses one action once however many times it is asked for, and each other action on its own', function (): void {
    $result = FixtureRunner::analyzeRepeat(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        'abortAction',
        'namedAbortAction',
    );

    expect($result['repeatIsMemoised'])->toBeTrue()
        ->and($result['otherIsSeparate'])->toBeTrue()
        // …and separate because it is a different action, not because the memo missed: the answers differ.
        ->and(json_encode($result['other']))->not->toBe(json_encode($result['first']));
})->group('fixture');

it('keys the memo finer than the label two closures in one file share', function (): void {
    // `ActionRef::symbol()` is `file::{closure}` for every closure in a routes file, so a memo keyed on it
    // would hand the second closure route the first one's analysis. The key is the whole ref.
    $result = FixtureRunner::analyzeRepeat(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        'abortAction',
        'namedAbortAction',
    );

    expect($result['closureSymbolsCollide'])->toBeTrue()
        ->and($result['closuresAreSeparate'])->toBeTrue();
})->group('fixture');

it('serves the memoised ask the answer a cold process gives, byte for byte', function (): void {
    // The locality rule as it applies to a memo: N documents in ONE process must publish what N separate
    // processes publish. `analyze` is the cold single-ask baseline, in its own subprocess with its own
    // container; both in-process asks have to match it exactly — a diagnostic raised while computing the
    // first answer and dropped from the second would show up right here, since diagnostics ride the
    // analysis rather than being emitted beside it.
    $cold = FixtureRunner::analyze(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        'abortAction',
    );
    $coldOther = FixtureRunner::analyze(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        'namedAbortAction',
    );
    $repeated = FixtureRunner::analyzeRepeat(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        'abortAction',
        'namedAbortAction',
    );

    expect(json_encode($repeated['first']))->toBe(json_encode($cold))
        ->and(json_encode($repeated['second']))->toBe(json_encode($cold))
        ->and(json_encode($repeated['other']))->toBe(json_encode($coldOther));
})->group('fixture');
