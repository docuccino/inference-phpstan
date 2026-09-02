<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Every shape an application writes an `HttpException` subclass's status in, and the status the
 * document publishes for it — at the throw, on a constructor, inside the factory the throw names,
 * one class up, inside a closure, or nowhere the analysis can read. Whether a surfaced exception
 * becomes an API error in the first place is {@see ThrowSurfacingTest}; the notice raised where the
 * status would not read is {@see ThrowStatusDiagnosticTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('publishes the status each construction shape writes', function (string $method, array $expected): void {
    sort($expected);

    expect(signalThrows($method))->toBe($expected);
})->with([
    // An exception that IS an HTTP status states it in its own constructor, where no name-keyed table can
    // see it. Each row is one of the shapes an application writes that in, and 500 — the answer a lookup
    // miss used to give all three — would be a failure the server does not have.
    'a status pinned through a private constructor default' => ['pinnedHttpStatus', ['ExportRejectedException@422']],
    'a status pinned as a literal two classes up' => ['inheritedHttpStatus', ['PortalUnavailableException@503']],
    'a status written at the throw, no constructor of its own' => ['httpStatusAtThrowSite', ['ExportLockedException@423']],
    'a status written at the throw, its argument NAMED' => ['namedHttpStatusAtThrowSite', ['ExportLockedException@423']],
    // The same default behind a PUBLIC constructor is no pin for the CLASS — any caller may pass another —
    // and it is still what THIS construction passes, because it left the slot empty. The pair below is the
    // point: the same `new`, one hop apart, and a document that answered them differently.
    'a public constructor default, at the throw' => ['defaultedHttpStatusAtThrowSite', ['ExportBlockedException@409']],
    'the same construction inside the factory the throw names' => ['defaultedHttpStatusInFactory', ['ExportBlockedException@409']],
    // A constructor that normalises the status it was handed: `none()` really builds a 400, so the 422 the
    // default names is a status the code does not state and no status at all is the honest answer.
    'a constructor that moves the status it was handed' => ['movedHttpStatus', ['ExportPartialException@null']],
    // …and the same defect one statement later, which is the row the FOLD SCOPE decides: folding the
    // forwarded argument in the body's end scope answers the 500 assigned after the parent call, a status
    // nothing was ever built with. Folding it at the call, where it is written, answers nothing at all.
    'a constructor that reuses the status after forwarding it' => ['supersededHttpStatus', ['ExportSupersededException@null']],
    // Nothing folds, which is what the diagnostic in ThrowStatusDiagnosticTest is raised for.
    'a factory that builds the class two ways' => ['unreadHttpStatus', ['ExportConflictException@null']],
    // A vendor exception the application throws itself is still the application's error; its status is
    // written where PHPStan strips the body, so it is documented without one rather than at a made-up 500.
    'a vendor exception thrown deliberately' => ['vendorHttpStatusAtThrowSite', ['ConflictHttpException@null']],
    // …and one only DECLARED by a vendor method is plumbing: being an HttpException subclass is not itself
    // a status, so nothing here is promoted to an API error.
    'a vendor @throws of a vendor HttpException subclass' => ['vendorDeclaredHttpStatus', []],
    // A class that pins nothing because its FACTORIES choose. The throw names one, and the class constant
    // it builds with folds through the factory's own scope like the literal beside it would.
    'a status the factory named at the throw builds with' => ['factoryHttpStatus', ['ExportUnsupportedException@422']],
    // The pair that makes the point: one class, two factories, two statuses, on two operations. A reader of
    // the class alone can only answer null for both, and answering 500 would invent a failure twice over.
    'one factory of a two-status class' => ['factoryDefaultedStatus', ['ExportConflictException@409']],
    'its sibling, the same class at another status' => ['factoryOverriddenStatus', ['ExportConflictException@403']],
    // Where the throw point carries NO construction, the class's own is the only thing left that can
    // answer — and a class that builds itself exactly one way has said its status once, in that one place.
    // Every row here surfaced the exception with no status at all until the class was asked.
    'a class that builds itself one way, thrown from a trait' => ['traitThrownStatus', ['ProbeStaleException@409']],
    'the same class reached by a rethrow' => ['rethrownStatus', ['ProbeStaleException@409']],
    // A closure is its own scope, so the analysed method's throw point is the CALL that was handed the
    // closure — a bare `Throwable`, or nothing at all. The status is written one scope in.
    'a status written at a throw inside a closure' => ['closureThrownStatus', ['ExportLockedException@423']],
    'a factory named at a throw inside a closure' => ['closureFactoryThrownStatus', ['ExportUnsupportedException@422']],
    'a closure held in a local before it is handed over' => ['heldClosureThrownStatus', ['ExportLockedException@423']],
    // The boundary of that hop, pinned rather than described: PHPStan models an arrow function with no
    // statement result, so it has no throw points to read and nothing is surfaced.
    'the same throw in an arrow function' => ['arrowThrownStatus', []],
    // …and the other boundary, counted rather than described: a closure spends one of descent's own
    // depth budget, so the throw three closures in is read and the 410 one nesting behind it is not.
    // The counted throw is written BEFORE the closure it is measured against, and that is load-bearing:
    // `transaction()` is generic over its callback's return from Laravel 13 on, so a closure that only
    // throws makes the call `never` and everything after it dead code the application cannot reach.
    // Written the other way round this row read 423 on Laravel 12 and nothing at all on Laravel 13.
    'closures nested past the descent budget' => ['nestedClosureThrownStatus', ['ExportLockedException@423']],
    // And the guard on all of it: a construction that PRESENTED itself and would not fold has said the
    // response is whatever was chosen at run time. What the class's own factory agrees on — a 409 — is no
    // evidence for THIS throw, so no status is the honest answer.
    'a construction whose status is chosen at run time' => ['runtimeStatusAtThrowSite', ['ExportBlockedException@null']],
    // …and the same guard where the construction is one ASSIGNMENT behind the throw, which is how a body
    // that decorates an exception before throwing it is written. The 451 is the status the code built,
    // and the class's own 409 would be a status this response never has.
    'a construction one assignment behind the throw' => ['heldConstructionAtThrowSite', ['ExportBlockedException@451']],
    'the same, with its status chosen at run time' => ['heldRuntimeConstructionAtThrowSite', ['ExportBlockedException@null']],
    // A class is built by its BASE too: `new static(503)` one class up builds this one, so a subclass
    // adding nothing still has a status, and one adding a factory of its own has two and states neither.
    'a factory the subclass inherits from its base' => ['inheritedFactoryStatus', ['ExportRelocatedException@503']],
    'a class its own base and its own factory build differently' => ['inheritedAgreementStatus', ['ExportOfflineException@null']],
    // Two closures handed to one call on ONE line are two bodies and two errors; a reader keying them by
    // line resolves both to the second, and the first error leaves the document without a word.
    'two closures written on one line' => ['pairedClosureThrownStatus', ['ExportLockedException@423', 'ExportUnsupportedException@422']],
    // A status pinned through a constant declared in another file entirely.
    'a status pinned through another file\'s constant' => ['constantPinnedStatus', ['ExportArchivedException@415']],
    // …and the same spelling whose constant is no status: the fold refuses 3 rather than publishing the
    // response key `"3"`, which is the degradation the range gate exists for.
    'a constant reaching the parent that is no status' => ['unreadableConstantStatus', ['ExportRelayedException@null']],
    // The factory a TRAIT writes. The `new static(429)` really does build this class, and neither reader of
    // a body is entitled to it: parsing the trait's file keys its methods under the trait, and the
    // analyser's walk of that file holds no bodies at all because PHPStan reads a trait in the using
    // class's context. So the honest answer is no status, with the notice beside it.
    'a factory the class gets from a trait' => ['traitFactoryStatus', ['ExportThrottledException@null']],
])->group('fixture');
