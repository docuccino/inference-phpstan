<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * "Covering is not agreeing": {@see ThrowStatusTest} has a row for each spelling of a throw, and a
 * row apiece proves nothing about whether two spellings of the SAME construction answer alike. Each
 * guard here states the rule independently rather than asking the code for it, and the last one
 * pins the chain an author is shown when a throw is two scopes down.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('answers the same status whether the construction is at the throw or one hop inside a factory', function (): void {
    // The two spellings each had a row, and what neither asked was whether they agree — they did not, by
    // 409 against nothing at all. The rule is stated here rather than asked of the code:
    // `new ExportBlockedException` leaves the status slot empty, PHP fills it with the 409 written on the
    // constructor, and where the same `new` sits is not a fact about the response.
    expect(signalThrows('defaultedHttpStatusAtThrowSite'))
        ->toBe(signalThrows('defaultedHttpStatusInFactory'))
        ->and(signalThrows('defaultedHttpStatusAtThrowSite'))->toBe(['ExportBlockedException@409']);
})->group('fixture');

it('answers the same status whether the throw is written inline or inside a closure', function (): void {
    // The same rule one scope in: each spelling has a row of its own, and neither asks whether they agree.
    // The rule is stated here rather than read off the code — where a `throw` is written is not a fact
    // about the response, so a closure the method hands to a callee that runs it owes the same answer the
    // method's own body would.
    expect(signalThrows('closureThrownStatus'))
        ->toBe(signalThrows('httpStatusAtThrowSite'))
        ->and(signalThrows('closureThrownStatus'))->toBe(['ExportLockedException@423'])
        ->and(signalThrows('heldClosureThrownStatus'))->toBe(signalThrows('closureThrownStatus'));
})->group('fixture');

it('names the closure the throw was written in', function (): void {
    // The chain is what an author is shown when they go looking, and a throw two scopes down that reports
    // only the action names a line with no `throw` on it.
    /** @var list<array<string, mixed>> $throws */
    $throws = throwsAnalysis('closureThrownStatus')['throws'];

    /** @var list<array<string, mixed>> $chain */
    $chain = $throws[0]['callChain'];
    $symbols = array_map(static fn (array $frame): string => (string) $frame['symbol'], $chain);

    expect($symbols)->toBe([
        'ThrowsController::closureThrownStatus',
        'ThrowsController::closureThrownStatus::{closure}',
    ]);
})->group('fixture');
