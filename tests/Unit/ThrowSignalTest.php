<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Inference\PhpStan\Throwing\ThrowSignal;

/**
 * The whole truth table of what the analysis calls an API error, an assertion, and vendor plumbing. Every
 * row states its answer from the rule rather than from the code: a throw is plumbing only where all three
 * of "not written here", "not declared by the application" and "no status anyone could read" hold at once;
 * of the rest, one with no status the application raises only where its own guarantees fail is an assertion.
 *
 * The rows that matter most are the ones a status NUMBER would get wrong: an exception pinning 500 is stating
 * an API fact, and demoting it because the number matches the fallback loses a real error response — behind
 * a failed type test as anywhere else, since a status the code states is a response whatever reaches it.
 */
it('demotes only a foreign declaration whose status fell back, and calls an assertion one only where no status was read', function (bool $isLiteral, bool $calleeIsApplication, bool $fellBack, bool $asserts, ThrowDisposition $expected): void {
    expect(ThrowSignal::disposition($isLiteral, $calleeIsApplication, $fellBack, $asserts))->toBe($expected);
})->with([
    'written here, status read' => [true, false, false, false, ThrowDisposition::Signal],
    'written here, status fell back' => [true, false, true, false, ThrowDisposition::Signal],
    'written here, application callee, status read' => [true, true, false, false, ThrowDisposition::Signal],
    'written here, application callee, status fell back' => [true, true, true, false, ThrowDisposition::Signal],
    'declared by the application, status read' => [false, true, false, false, ThrowDisposition::Signal],
    'declared by the application, status fell back' => [false, true, true, false, ThrowDisposition::Signal],
    // The one that a `$status !== 500` test got wrong: the class states its status, and 500 is a status.
    'declared elsewhere, status read' => [false, false, false, false, ThrowDisposition::Signal],
    'declared elsewhere, nothing stated a status' => [false, false, true, false, ThrowDisposition::Internal],
    // An assertion: the application raises it with no status, only where its own guarantees fail.
    'written here, status fell back, asserting' => [true, false, true, true, ThrowDisposition::Assertion],
    'declared by the application, status fell back, asserting' => [false, true, true, true, ThrowDisposition::Assertion],
    // A status the code states is a refusal the request gets, whatever guard it sits behind.
    'written here, status read, asserting' => [true, false, false, true, ThrowDisposition::Signal],
    'declared elsewhere, status read, asserting' => [false, false, false, true, ThrowDisposition::Signal],
    // Plumbing stays plumbing: the application neither wrote nor declared it.
    'declared elsewhere, nothing stated a status, asserting' => [false, false, true, true, ThrowDisposition::Internal],
]);
