<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Core\Support\AtomicFile;
use RuntimeException;

/**
 * Edits one file of the provisioned fixture app and puts it back, while the rest of the suite is
 * analysing that same tree in thirteen other processes.
 *
 * Two mechanisms, closing two different holes. {@see write()} REPLACES rather than truncates, so a
 * concurrent analysis reads the whole old file or the whole new one and never half of one:
 * `file_put_contents` opens with `O_TRUNC` and holds no lock, and a reader landing in that window gets
 * a `ParseError`, reports no throws, and fails a row in a file that touched nothing. And
 * {@see exclusively()} serialises the WRITERS against each other, because a fragment-cache row hashes
 * its dependency files before it edits one: a second writer holding its own edit while the first
 * hashes makes the first read its untouched entry back as stale.
 *
 * Readers take no lock and wait for nothing, which is deliberate — a shared lock held across a
 * multi-second subprocess analysis would leave `flock(LOCK_EX)` starving behind a queue of them. What a
 * reader can still see is the appended text, so that is chosen to be something it cannot care about: a
 * comment past the end of a file moves no line and changes no type the analyser reports.
 */
final class FixtureEdit
{
    /**
     * The writer lock. Keyed by the tree it guards rather than the repo it lives in, so sibling
     * worktrees sharing one `TMPDIR` do not lock against each other's fixture app — and outside the
     * tree, so it needs no gitignore entry and exists whether or not the app is provisioned.
     */
    public static function lockPath(): string
    {
        return sys_get_temp_dir().'/docuccino-fixture-writer-'.md5(FixtureRunner::appRoot()).'.lock';
    }

    /**
     * Run $work as the fixture tree's only writer.
     *
     * Nothing in $work may edit the tree again through this class: the lock is held on one file handle
     * and `flock` is per-handle, so a nested call would wait on a lock this process already holds.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function exclusively(callable $work): mixed
    {
        $handle = fopen(self::lockPath(), 'c');

        if ($handle === false) {
            throw new RuntimeException('cannot open the fixture writer lock at '.self::lockPath());
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('cannot take the fixture writer lock at '.self::lockPath());
            }

            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Replace $path's contents, atomically. Raises rather than returning false: the second call of a
     * pair is the RESTORE, and a restore that quietly did nothing leaves an edited file behind for
     * every later run of the suite to trip over.
     */
    public static function write(string $path, string $contents): void
    {
        if (! AtomicFile::write($path, $contents)) {
            throw new RuntimeException('cannot replace the fixture file at '.$path);
        }
    }
}
