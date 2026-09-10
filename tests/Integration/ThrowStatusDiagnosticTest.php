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
    // The status handed to `abort()` chosen at run time. The exception is the FRAMEWORK's own, so
    // this is the row a notice gated on where the exception class is declared reported nothing for —
    // while the document published the unplaced status all the same.
    'a status argument chosen at run time' => ['dynamicAbortStatus', 'Symfony\\Component\\HttpKernel\\Exception\\HttpException'],
    'the same one argument along' => ['dynamicAbortIfStatus', 'Symfony\\Component\\HttpKernel\\Exception\\HttpException'],
    // The same exception CONSTRUCTED here rather than aborted with. The class is Symfony's either way,
    // and the expression that will not fold is this line either way — so a reader that answers these two
    // differently is reading the wrong file, which is what it did.
    'a vendor class built here with a status chosen at run time' => ['dynamicVendorConstructionStatus', 'Symfony\\Component\\HttpKernel\\Exception\\HttpException'],
    'a factory that builds the class two ways' => ['unreadHttpStatus', 'App\\Exceptions\\ExportConflictException'],
    'a constructor that moves the status it was handed' => ['movedHttpStatus', 'App\\Exceptions\\ExportPartialException'],
    'a constructor that reuses the status after forwarding it' => ['supersededHttpStatus', 'App\\Exceptions\\ExportSupersededException'],
    // A status chosen at run time, which is the one thing the notice's help text asks the author to
    // change — and the class's own agreement may not answer over the top of it.
    'a construction whose status is chosen at run time' => ['runtimeStatusAtThrowSite', 'App\\Exceptions\\ExportBlockedException'],
    'the same construction one assignment behind the throw' => ['heldRuntimeConstructionAtThrowSite', 'App\\Exceptions\\ExportBlockedException'],
    // A class its base and its own factory build at two statuses, reached through a guard that declares
    // it: the guard's own two `throw`s are read, and they name the two statuses rather than one.
    'a class built two ways, reached through a guard that declares it' => ['inheritedAgreementStatus', 'App\\Exceptions\\ExportOfflineException'],
    // The same class reached by a RETHROW, which is the one shape left where nothing on the way to the
    // throw builds the exception and only the class could have answered.
    'a class built two ways, reached by a rethrow' => ['rethrownAgreementStatus', 'App\\Exceptions\\ExportOfflineException'],
    // Two shapes the author really can act on: a constant that is no status, and a factory written in a
    // trait — moving either into the class the status belongs to is what the notice asks for.
    'a constant reaching the parent that is no status' => ['unreadableConstantStatus', 'App\\Exceptions\\ExportRelayedException'],
    'a factory the class gets from a trait' => ['traitFactoryStatus', 'App\\Exceptions\\ExportThrottledException'],
    // Reached through descent, which is what makes the SITE the notice names worth checking: the
    // throw is a call away from the action.
    'a throw a call away, in an injected collaborator' => ['deepUnreadHttpStatus', 'App\\Exceptions\\ExportConflictException'],
])->group('fixture');

/**
 * What the notice is FOR: the reader can see the response is filed under a status nothing stated, and
 * cannot see which throw did it. So the sentence names the exception, the file and line the `throw` is
 * written at, and which fold gave up — and the site is the deep one, never the action line the route
 * entered by, which is the only part of this the provenance trail already carries.
 */
it('names the throw site and the fold that gave up, not the line the route entered by', function (): void {
    $reported = unreadStatusDiagnostics('deepUnreadHttpStatus');

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('ExportProbeQuery.php')
        ->and($reported[0])->toContain('the construction behind the throw does not fold to one status')
        ->and($reported[0])->not->toContain('ThrowsController.php');
})->group('fixture');

/**
 * The site the notice names is the last frame of the throw's own call chain — the same code the
 * response's provenance is built from — so the two halves of the answer meet at one file and line
 * rather than at two the reader has to relate.
 *
 * And it names it PUBLISHABLY. A diagnostic is embedded in the document, so the run the analyser hands
 * back — an absolute path off the machine that ran it — may not leave the engine as it stands. The
 * adapter scrubs what crosses into a fragment as well, but an engine is a contract another host can
 * call, so the guarantee is asserted here rather than inherited from a caller.
 */
it('names the same site the throw itself carries, without naming the machine', function (string $method): void {
    $analysis = throwsAnalysis($method);

    /** @var list<array<string, mixed>> $throws */
    $throws = $analysis['throws'];
    $unplaced = array_values(array_filter($throws, static fn (array $throw): bool => $throw['httpStatusHint'] === null));

    expect($unplaced)->toHaveCount(1);

    /** @var list<array{symbol: string, location: array{file: string, line: int}}> $chain */
    $chain = $unplaced[0]['callChain'];
    $deepest = $chain[count($chain) - 1]['location'];
    $relative = str_replace(FixtureRunner::appRoot().'/', '', $deepest['file']);

    // The identity the analyser carries really is absolute, so the row below is about a crossing that
    // happens rather than about a path that was relative all along.
    expect($deepest['file'])->toStartWith('/')
        ->and($relative)->not->toStartWith('/');

    expect(unreadStatusDiagnostics($method)[0] ?? '')
        ->toContain($relative.':'.$deepest['line'])
        ->not->toContain($deepest['file']);
})->with(['deepUnreadHttpStatus', 'unreadHttpStatus', 'dynamicAbortStatus', 'dynamicVendorConstructionStatus'])->group('fixture');

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
    // The named-factory idiom behind a callee that DECLARES the throw, which is how an application
    // documents a guard. Its status is read one hop on, so the author is asked for nothing — and the
    // notice these two used to earn named a class whose every factory already states a status, which is
    // the shape that trains a reader to ignore the channel.
    'manifestStatusDeclaredByCallee',
    'manifestStatusDeclaredNotFound',
    'modularDeclaredStatus',
])->group('fixture');
