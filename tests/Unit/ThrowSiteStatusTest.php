<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Inference\PhpStan\Throwing\ThrowSiteStatus;

/**
 * What a SET of `throw` readings states, driven in this process — the rule a `@throws` on a callee sends
 * the status read into, with no PHPStan scope involved in deciding it.
 *
 * The interesting half is the failures, because each one hands the caller a different answer about WHOSE
 * code the fold gave up in, and the document publishes a placeholder status off all three alike.
 */

/**
 * @return array{status: int|null, spoke: bool, foldedHere: bool, file: string}
 */
function reading(?int $status, bool $spoke = true, bool $foldedHere = false, string $file = '/app/Services/Guard.php'): array
{
    return ['status' => $status, 'spoke' => $spoke, 'foldedHere' => $foldedHere, 'file' => $file];
}

it('states the status every throw in the set folded to', function (): void {
    expect(ThrowSiteStatus::stated([reading(404)]))
        ->toBe(['status' => 404, 'spoke' => true, 'read' => null])
        // Two `throw`s naming two factories of one status is still that status: what a guard raises is
        // one response however many lines raise it.
        ->and(ThrowSiteStatus::stated([reading(409), reading(409)]))
        ->toBe(['status' => 409, 'spoke' => true, 'read' => null]);
});

it('states nothing for a set that folded to two statuses, and names no file for it', function (): void {
    // Two responses sharing one exception class. The folds themselves all succeeded, so no expression
    // gave up and there is no file to send a reader to — the class's own declarations are the subject.
    expect(ThrowSiteStatus::stated([reading(503), reading(413)]))
        ->toBe(['status' => null, 'spoke' => true, 'read' => null]);
});

it('names the file whose expression would not fold', function (): void {
    expect(ThrowSiteStatus::stated([reading(null, foldedHere: true, file: '/app/Services/Ledger.php')]))
        ->toBe(['status' => null, 'spoke' => true, 'read' => '/app/Services/Ledger.php'])
        // …and one folded status beside it does not rescue the set: the response could be either.
        ->and(ThrowSiteStatus::stated([reading(422), reading(null, foldedHere: true)]))
        ->toBe(['status' => null, 'spoke' => true, 'read' => '/app/Services/Guard.php']);
});

it('answers the same file whichever order the walk met two that gave up', function (): void {
    // Determinism, and locality with it: the file a notice names may not be a function of which `throw`
    // the analyser handed over first. A reader keying on arrival order answers `Beta` for one build and
    // `Alpha` for the next after an unrelated edit reorders the body.
    $alpha = reading(null, foldedHere: true, file: '/app/Services/Alpha.php');
    $beta = reading(null, foldedHere: true, file: '/app/Services/Beta.php');

    expect(ThrowSiteStatus::stated([$beta, $alpha]))
        ->toBe(ThrowSiteStatus::stated([$alpha, $beta]))
        ->and(ThrowSiteStatus::stated([$beta, $alpha])['read'])->toBe('/app/Services/Alpha.php');
});

it('leaves a construction with no status slot to the class, without naming a file', function (): void {
    // The `throw` presented a construction and there was no argument at that site to read at all, so the
    // fold really was the exception class's own declarations — which the caller decides actionability from,
    // and a file here would send the reader to the wrong one.
    expect(ThrowSiteStatus::stated([reading(null)]))
        ->toBe(['status' => null, 'spoke' => true, 'read' => null])
        // Mixed with one that DID fold, the set still cannot speak: not every way the callee raises it
        // states that status.
        ->and(ThrowSiteStatus::stated([reading(409), reading(null)]))
        ->toBe(['status' => null, 'spoke' => true, 'read' => null]);
});

it('discards the whole set when one throw built nothing this hop can read', function (): void {
    // A rethrow, or a call one hop further down. The remaining readings are a SUBSET of what the callee
    // raises, so agreeing over them would publish one branch's status for every branch — and `spoke`
    // false is what keeps the class's own agreement entitled to answer instead.
    expect(ThrowSiteStatus::stated([reading(404), reading(null, spoke: false)]))
        ->toBe(['status' => null, 'spoke' => false, 'read' => null])
        // Order cannot matter here either: the unreadable reading wins from anywhere in the set.
        ->and(ThrowSiteStatus::stated([reading(null, spoke: false), reading(404)]))
        ->toBe(['status' => null, 'spoke' => false, 'read' => null]);
});

it('says nothing at all for a callee that throws the class nowhere the walk saw', function (): void {
    // The `@throws` names a class the body does not raise here — a declaration inherited from an
    // interface, or one left behind by an edit. Silence, so the reads after this one still run.
    expect(ThrowSiteStatus::stated([]))->toBe(['status' => null, 'spoke' => false, 'read' => null]);
});
