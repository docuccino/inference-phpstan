<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Inference\PhpStan\Throwing\ThrowSignal;

/**
 * The whole truth table of what the analysis calls an API error and what it files as vendor plumbing. Every
 * row states its answer from the rule rather than from the code: a throw is plumbing only where all three
 * of "not written here", "not declared by project code" and "no status anyone could read" hold at once.
 *
 * The row that matters most is the one a status NUMBER would get wrong: an exception pinning 500 is stating
 * an API fact, and demoting it because the number matches the fallback loses a real error response.
 */
it('demotes only a foreign declaration whose status fell back', function (bool $isLiteral, bool $calleeIsProject, bool $fellBack, ThrowDisposition $expected): void {
    expect(ThrowSignal::disposition($isLiteral, $calleeIsProject, $fellBack))->toBe($expected);
})->with([
    'written here, status read' => [true, false, false, ThrowDisposition::Signal],
    'written here, status fell back' => [true, false, true, ThrowDisposition::Signal],
    'written here, project callee, status read' => [true, true, false, ThrowDisposition::Signal],
    'written here, project callee, status fell back' => [true, true, true, ThrowDisposition::Signal],
    'declared by project code, status read' => [false, true, false, ThrowDisposition::Signal],
    'declared by project code, status fell back' => [false, true, true, ThrowDisposition::Signal],
    // The one that a `$status !== 500` test got wrong: the class states its status, and 500 is a status.
    'declared elsewhere, status read' => [false, false, false, ThrowDisposition::Signal],
    'declared elsewhere, nothing stated a status' => [false, false, true, ThrowDisposition::Internal],
]);
