<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\FixtureEdit;

/*
 * The two claims the fixture-tree edits rest on, executed rather than asserted — and then the scan that
 * keeps the next writer inside them.
 *
 * The suite analyses the provisioned fixture app out of fourteen processes at once, and three suites
 * edit a file of it to prove a fragment goes stale. Reproduced before this was closed: hold the gap
 * `file_put_contents` leaves between its truncate and its write, run the row that reads the same file,
 * and `ExportArchivedException@415` comes back as an empty throw set — a row in another file failing for
 * a reason it has no way to name.
 */

/** A scratch file to write over, taken away again whichever way the row ends. */
function editableFile(string $label, string $contents): string
{
    $path = sys_get_temp_dir().'/docuccino-fixture-edit-'.$label.'-'.uniqid('', true).'.txt';
    file_put_contents($path, $contents);

    return $path;
}

it('replaces a file rather than truncating it, so a reader mid-write sees the whole old one', function (): void {
    // The property that makes a concurrent analysis safe, stated the way a reader experiences it: a
    // handle opened before the write goes on reading the complete previous contents, because the write
    // landed on a different inode and the rename swapped the directory entry. A truncating write would
    // have this handle reading a prefix — which is the ParseError.
    $path = editableFile('replaces', "old contents, long enough to be worth truncating\n");
    $reader = fopen($path, 'rb');
    expect($reader)->not->toBeFalse();

    try {
        FixtureEdit::write($path, "new\n");

        expect(stream_get_contents($reader))->toBe("old contents, long enough to be worth truncating\n")
            ->and(file_get_contents($path))->toBe("new\n");
    } finally {
        fclose($reader);
        @unlink($path);
    }
});

it('raises rather than reporting nothing when a replacement cannot land', function (): void {
    // The restore is the second call of a pair, and one that quietly did nothing leaves an edited
    // fixture file behind for every later run of the suite — including the drift check, which would then
    // name a file the developer never touched.
    $directory = sys_get_temp_dir().'/docuccino-fixture-edit-absent-'.uniqid('', true);

    expect(static fn (): mixed => FixtureEdit::write($directory.'/nowhere.txt', 'x'))
        ->toThrow(RuntimeException::class, 'cannot replace the fixture file');
});

it('lets one writer at a time hold the tree', function (): void {
    // The other hole, and a different one: two writers do not tear a file, they poison each other's
    // reading of it. A fragment-cache row hashes its dependency files and THEN edits one, so a second
    // writer holding its own edit while the first hashes has the first read its own untouched entry back
    // as stale. The lock is proved against the real lock file, from a process of its own, because
    // `flock` is per-handle and a same-process attempt would say nothing about another worker.
    $probe = escapeshellarg(
        '$h = fopen('.var_export(FixtureEdit::lockPath(), true).', "c");'
        .'echo flock($h, LOCK_EX | LOCK_NB) ? "taken" : "blocked";',
    );
    $attempt = static fn (): string => (string) shell_exec(escapeshellarg(PHP_BINARY).' -r '.$probe);

    $whileHeld = FixtureEdit::exclusively($attempt);

    expect($whileHeld)->toBe('blocked')
        // …and released afterwards, so the guard is a lock rather than a permanent one-way door.
        ->and($attempt())->toBe('taken');
});

/**
 * Every write the engine's own test tree performs with a truncating call, keyed on the function it sits
 * in. `FixtureEdit` is not in the list because it makes no such call — it replaces.
 *
 * @return list<string>
 */
function truncatingWritesInEngineTests(): array
{
    return sourceSitesIn(
        dirname(__DIR__),
        static fn (string $source): array => globalCallSites($source, ['file_put_contents', 'ftruncate']),
        dirname(__DIR__, 3),
    );
}

it('leaves the engine suite no truncating write but the ones that own their file', function (): void {
    // Three suites had this call against a shared tree, and the reviewer's report named two of them —
    // which is what a report does. An allow-list rather than a ban, because a test writing a file it
    // created itself has no reader to race: what may not happen is a truncating write to a path the rest
    // of the suite is reading.
    expect(truncatingWritesInEngineTests())->toBe([
        // The scratch file the rows above write over. Never in the fixture tree.
        'inference-phpstan/tests/Unit/FixtureEditTest.php::file_put_contents called in editableFile',
        // A neon config the row writes into a temp directory of its own and hands to one analysis.
        'inference-phpstan/tests/Unit/GeneratedNeonTest.php::file_put_contents called in {file}',
    ]);
});

it('recognises a truncating write, and only a call to one', function (): void {
    // The scanner's own proof: the names it looks for are ordinary English as well as functions, so a
    // grep over this repo's docblocks would flag the paragraph that explains the defect. Each shape below
    // is one a line scan gets wrong in one direction or the other.
    $source = <<<'PHP'
        <?php

        /** Never reach for file_put_contents here — it truncates. */
        final class Sneaky
        {
            public const string ADVICE = 'file_put_contents($p, $c) is the wrong call';

            public function file_put_contents(string $p): void {}

            public function run(string $p): void
            {
                $this->file_put_contents($p);
                self::file_put_contents($p);
                $name = file_put_contents;
                file_put_contents($p, 'x');
                ftruncate($h, 0);
            }
        }
        PHP;

    expect(globalCallSites($source, ['file_put_contents', 'ftruncate']))
        ->toBe(['file_put_contents called in run', 'ftruncate called in run']);
});
