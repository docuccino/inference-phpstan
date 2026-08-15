<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Analysis\DescentBudget;
use Docuccino\Inference\PhpStan\Analysis\RefinedResponse;

/**
 * The refiner's bound arithmetic, driven directly: it decides whether a memoised helper shape may be
 * handed to a caller, and getting it wrong makes one route's documented body depend on which unrelated
 * route the build analysed first. The refiner half that produces the shapes needs PHPStan and is proven
 * against the real engine (the `fixture` group); the accounting is pure, so it is proven here.
 */
it('lets a callee frame descend up to the depth bound, and no further', function (int $depth, bool $expected): void {
    expect((new DescentBudget(4, 40))->withinDepth($depth))->toBe($expected);
})->with([
    'root' => [0, true],
    'inside' => [3, true],
    'exactly the bound' => [4, true],
    'past it' => [5, false],
]);

it('charges the file budget for a new file only, never for a revisit', function (): void {
    $budget = new DescentBudget(4, 2);
    $budget->touch('a.php');

    expect($budget->withinBudget('b.php'))->toBeTrue(); // one slot left
    $budget->touch('b.php');

    expect($budget->withinBudget('c.php'))->toBeFalse() // spent
        ->and($budget->withinBudget('a.php'))->toBeTrue(); // already touched: free
});

it('drains touched files sorted and de-duplicated, then starts the next analysis empty', function (): void {
    $budget = new DescentBudget(4, 40);
    $budget->touch('z.php');
    $budget->touch('a.php');
    $budget->touch('z.php');

    expect($budget->takeFiles())->toBe(['a.php', 'z.php'])
        ->and($budget->takeFiles())->toBe([]);
});

it('reports each bound hit exactly once', function (): void {
    $budget = new DescentBudget(4, 40);

    expect($budget->takeTruncations())->toBe(0);

    $budget->truncate();
    $budget->truncate();

    expect($budget->takeTruncations())->toBe(2)
        ->and($budget->takeTruncations())->toBe(0); // already reported — not attributed to the next analysis
});

it('marks a callee as descending only between open and close (the cycle guard)', function (): void {
    $budget = new DescentBudget(4, 40);

    expect($budget->isDescending('A::a'))->toBeFalse();

    $frame = $budget->open('A::a', 1);
    expect($budget->isDescending('A::a'))->toBeTrue();

    $budget->close('A::a', $frame, null);
    expect($budget->isDescending('A::a'))->toBeFalse();
});

it('answers a miss and a memoised "nothing recoverable" differently', function (): void {
    $budget = new DescentBudget(4, 40);

    expect($budget->replay('A::a', 1))->toBeNull(); // never computed

    $budget->close('A::a', $budget->open('A::a', 1), null);

    expect($budget->replay('A::a', 1))->toBe([null]); // computed, and the answer was "nothing"
});

it('serves a completed descent and re-contributes the files it touched', function (): void {
    $budget = new DescentBudget(4, 40);
    $shape = new RefinedResponse(status: new LiteralT(418));

    $frame = $budget->open('A::a', 1);
    $budget->touch('a.php');
    $budget->close('A::a', $frame, $shape);
    $budget->takeFiles(); // next analysis: nothing touched yet

    expect($budget->replay('A::a', 1))->toBe([$shape])
        // Cache soundness: a route served the memo still depends on the file the shape came from.
        ->and($budget->takeFiles())->toBe(['a.php']);
});

it('never memoises a descent that hit a bound', function (): void {
    $budget = new DescentBudget(4, 40);

    $frame = $budget->open('A::a', 1);
    $budget->truncate(); // something below ran out of bound
    $budget->close('A::a', $frame, new RefinedResponse(status: new LiteralT(418)));

    // The result was used by the analysis that computed it, but the next caller recomputes rather than
    // inheriting a richness that depended on how much bound was already spent.
    expect($budget->replay('A::a', 1))->toBeNull();
});

it('refuses to serve an entry the caller has no depth left to have earned', function (): void {
    $budget = new DescentBudget(4, 40);

    // A::a computed at depth 1 and descended one level below itself.
    $outer = $budget->open('A::a', 1);
    $inner = $budget->open('B::b', 2);
    $budget->close('B::b', $inner, null);
    $budget->close('A::a', $outer, new RefinedResponse(status: new LiteralT(418)));

    expect($budget->replay('A::a', 3))->not->toBeNull() // 3 + 1 level still fits under 4
        ->and($budget->replay('A::a', 4))->toBeNull();  // 4 + 1 would descend past the bound
});

it('refuses to serve an entry the caller has no file budget left to have earned', function (): void {
    $budget = new DescentBudget(4, 3);

    $frame = $budget->open('A::a', 1);
    $budget->touch('a.php');
    $budget->touch('b.php');
    $budget->close('A::a', $frame, new RefinedResponse(status: new LiteralT(418)));

    $budget->takeFiles();
    $budget->touch('x.php');
    $budget->touch('y.php');

    // Replaying would cost two files it has not touched, and only one slot is left.
    expect($budget->replay('A::a', 1))->toBeNull();

    $budget->takeFiles();
    $budget->touch('a.php'); // one of the entry's own files: replaying costs one more, which fits

    expect($budget->replay('A::a', 1))->not->toBeNull();
});

it('costs a nested descent to its parent, replayed levels included', function (): void {
    $budget = new DescentBudget(4, 40);
    $shape = new RefinedResponse(status: new LiteralT(418));

    // A::a → B::b, each in its own file.
    $outer = $budget->open('A::a', 1);
    $budget->touch('a.php');
    $inner = $budget->open('B::b', 2);
    $budget->touch('b.php');
    $budget->close('B::b', $inner, $shape);
    $budget->close('A::a', $outer, $shape);

    $budget->takeFiles();

    // The parent owns the whole chain's files, so a route reaching only A::a still depends on b.php.
    $budget->replay('A::a', 1);
    expect($budget->takeFiles())->toBe(['a.php', 'b.php']);

    // C::c reaches A::a through the memo, so it inherits A::a's depth cost: 1 level for A, 1 below it.
    $frame = $budget->open('C::c', 1);
    $budget->replay('A::a', 2);
    $budget->close('C::c', $frame, $shape);

    expect($budget->replay('C::c', 2))->not->toBeNull() // 2 + 2 == the bound
        ->and($budget->replay('C::c', 3))->toBeNull();  // 3 + 2 is past it
});
