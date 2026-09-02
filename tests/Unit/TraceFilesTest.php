<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Trace\TraceFiles;

/**
 * The trace's file bookkeeping, driven directly: it answers both what the walk may still open and what
 * the fragment it feeds invalidates on, and those are two different questions. Conflating them is a
 * product regression rather than a cache bug — a dependency charged a budget slot makes a trace stop
 * one hop short and a subsystem recover less than it did. The walking half needs PHPStan and is proven
 * against the real engine (the `fixture` group); the accounting is pure, so it is proven here.
 */
it('charges the budget for a new file only, never for a revisit', function (): void {
    $files = new TraceFiles(2);

    expect($files->admit('a.php'))->toBeTrue()
        ->and($files->admit('b.php'))->toBeTrue()
        ->and($files->admit('c.php'))->toBeFalse() // spent
        ->and($files->admit('a.php'))->toBeTrue(); // already open: free
});

it('records a written-in file without ever spending a slot on it', function (): void {
    // The whole reason the two sets are separate. A trait's body is handed to the walk of the using
    // class's file, so the trait was read by a walk already paid for; charging it again would buy a
    // shorter trace and nothing else. With one slot left, three of them must still leave it free.
    $files = new TraceFiles(2);
    $files->admit('a.php');

    $files->depend('t1.php');
    $files->depend('t2.php');
    $files->depend('t3.php');

    expect($files->admit('b.php'))->toBeTrue()   // the slot the depends must not have taken
        ->and($files->admit('c.php'))->toBeFalse(); // and only ever the one
});

it('never opens a file it was only asked to depend on', function (): void {
    // A dependency is not an admission: nothing may walk a file on the strength of having recorded it.
    $files = new TraceFiles(1);
    $files->depend('t.php');

    expect($files->admit('a.php'))->toBeTrue()
        ->and($files->admit('t.php'))->toBeFalse(); // the budget is spent, and t.php was never open
});

it('reports every file it read from, opened or written in, once each', function (): void {
    $files = new TraceFiles(40);
    $files->admit('a.php');
    $files->depend('t.php');
    $files->admit('a.php');
    $files->depend('t.php');
    $files->depend('a.php');

    expect($files->all())->toBe(['a.php', 't.php'])
        ->and((new TraceFiles(40))->all())->toBe([]);
});

it('reports nothing it was refused the budget to open', function (): void {
    // The set is what the trace READ, so a file the budget declined is not on it — the walk never
    // reached whatever it was going to say.
    $files = new TraceFiles(1);
    $files->admit('a.php');
    $files->admit('b.php');

    expect($files->all())->toBe(['a.php']);
});
