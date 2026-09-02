<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Where `inference.http-exception-status-unread` fires and where it stays silent. A diagnostic
 * earns its place by where it fires, so the silent half is the half that matters: every shape whose
 * status the analysis DID read is asserted to raise nothing, as is a class whose author could not
 * act on the notice anyway. The statuses themselves are {@see ThrowStatusTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('names the class whose HTTP status it could not read', function (string $method, string $fqcn): void {
    $reported = unreadStatusDiagnostics($method);

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toContain($fqcn);
})->with([
    'a factory that builds the class two ways' => ['unreadHttpStatus', 'App\\Exceptions\\ExportConflictException'],
    'a constructor that moves the status it was handed' => ['movedHttpStatus', 'App\\Exceptions\\ExportPartialException'],
    'a constructor that reuses the status after forwarding it' => ['supersededHttpStatus', 'App\\Exceptions\\ExportSupersededException'],
    // A status chosen at run time, which is the one thing the notice's help text asks the author to
    // change — and the class's own agreement may not answer over the top of it.
    'a construction whose status is chosen at run time' => ['runtimeStatusAtThrowSite', 'App\\Exceptions\\ExportBlockedException'],
    'the same construction one assignment behind the throw' => ['heldRuntimeConstructionAtThrowSite', 'App\\Exceptions\\ExportBlockedException'],
    // A class its base and its own factory build at two statuses, reached where nothing says which ran.
    'a class built two ways, reached with no construction' => ['inheritedAgreementStatus', 'App\\Exceptions\\ExportOfflineException'],
    // Two shapes the author really can act on: a constant that is no status, and a factory written in a
    // trait — moving either into the class the status belongs to is what the notice asks for.
    'a constant reaching the parent that is no status' => ['unreadableConstantStatus', 'App\\Exceptions\\ExportRelayedException'],
    'a factory the class gets from a trait' => ['traitFactoryStatus', 'App\\Exceptions\\ExportThrottledException'],
])->group('fixture');

it('says nothing where the status read, and nothing about a class the author does not own', function (string $method): void {
    expect(unreadStatusDiagnostics($method))->toBe([]);
})->with([
    'pinnedHttpStatus',
    'inheritedHttpStatus',
    'httpStatusAtThrowSite',
    'namedHttpStatusAtThrowSite',
    'defaultedHttpStatusAtThrowSite',
    'defaultedHttpStatusInFactory',
    'factoryHttpStatus',
    'factoryDefaultedStatus',
    'factoryOverriddenStatus',
    // The two vendor shapes: the status is unread in both, and the remedy the notice names is an edit to
    // `vendor/` — the non-actionable firing that trains a reader to ignore the channel.
    'vendorHttpStatusAtThrowSite',
    'vendorDeclaredHttpStatus',
    // And nothing for a plain domain exception either: it is not an HttpException, so there is no status
    // on it to have failed to read.
    'deepUndeclared',
    // The shapes the class now answers for itself, each of which used to earn a notice naming a class
    // whose author had already written the status exactly once.
    'traitThrownStatus',
    'rethrownStatus',
    'closureThrownStatus',
    'closureFactoryThrownStatus',
    'heldClosureThrownStatus',
    // Nothing is surfaced from an arrow function at all, so there is no class to name.
    'arrowThrownStatus',
    'nestedClosureThrownStatus',
    // The construction one assignment behind the throw, and the base's factory the subclass inherits:
    // both name a status, so neither class is one the author is asked about.
    'heldConstructionAtThrowSite',
    'inheritedFactoryStatus',
    'pairedClosureThrownStatus',
    'constantPinnedStatus',
])->group('fixture');
