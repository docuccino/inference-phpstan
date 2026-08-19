<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use WeakMap;

/**
 * Walks a file's nodes once and replays that walk to every later consumer, so resolving scope over a file —
 * the expensive half of an analysis — is paid once per build rather than once per question. Several consumers
 * ask the same file the same walk: a route's method harvest, then the Query-Builder trace, the inline-rules
 * trace, a pagination trace, and the same again for every other route in the file.
 *
 * The invariant the layer rests on: **a replayed walk and the walk that recorded it are indistinguishable.**
 * Every consumer — the recording one included — is handed the STABILISED scope
 * ({@see RuntimeAdapter::stableScope()}), in `NodeScopeResolver`'s own callback order, so the first walk and
 * the tenth see the same nodes with scopes answering the same `getType()`. That is what licenses every
 * abandon, discard and clear below: a recording that is missing is pure cost — one more live pass — and
 * never a different answer. Not every consumer can come through here; the exception and its reason are
 * `PhpStanTypeEngine::traceClosure()`'s docblock. See docs/design/inference-embedding.md §2.
 *
 * @internal
 *
 * @phpstan-type RecordedWalk list<array{Node, Scope}>
 */
final class FileWalks
{
    /** Fraction of the process's memory ceiling a recording may let usage reach before it is abandoned. */
    private const MEMORY_HEADROOM = 0.7;

    /** php.ini shorthand suffixes, in bytes. */
    private const MEMORY_UNITS = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];

    /** Digits that always fit a 64-bit int, so the scaling below is bounds-checked rather than saturating. */
    private const MEMORY_MAX_DIGITS = 18;

    /**
     * Recorded walks by normalised file, each stamped with the analysed-file-set size it was made at.
     *
     * @var array<string, array{nodes: RecordedWalk, analysed: int}>
     */
    private array $recordings = [];

    private int $recordedNodes = 0;

    /**
     * Files whose recording was abandoned mid-pass. Remembered so a later ask goes straight to a live pass
     * rather than re-paying the accumulation it is only going to throw away again.
     *
     * @var array<string, true>
     */
    private array $oversized = [];

    /** True while any live pass is in flight. */
    private bool $walking = false;

    /** Memory usage a recording stops at, or null when the process has no readable ceiling. */
    private readonly ?int $memoryCeiling;

    /**
     * @param  int  $nodeBudget  total recorded nodes to retain — a ceiling on this layer's memory, sized well
     *                           above what a large application walks (docs/design/inference-embedding.md §2);
     *                           overridable so the mechanics are testable at a budget of a few nodes
     * @param  int|null  $memoryCeiling  bytes usage may reach before recording is abandoned; null reads the
     *                                   process's own `memory_limit`
     */
    public function __construct(
        private readonly RuntimeAdapter $adapter,
        private readonly int $nodeBudget = 100_000,
        ?int $memoryCeiling = null,
    ) {
        $this->memoryCeiling = $memoryCeiling ?? self::ceilingFromIni();
    }

    /**
     * Drive `$callback(PhpParser\Node, PHPStan\Analyser\Scope)` over every node of a file, from the
     * recording when there is one and from a live pass — recorded as it goes — when there is not.
     */
    public function walk(string $file, callable $callback): void
    {
        $key = $this->adapter->normalize($file);

        $recording = $this->recordings[$key] ?? null;
        if ($recording !== null) {
            // A recording made before the analysed set grew can answer with less than a live pass would:
            // PHPStan gates trait inlining on that set, so a file primed since is richer now than it was.
            if ($recording['analysed'] === $this->adapter->analysedFileCount()) {
                foreach ($recording['nodes'] as [$node, $scope]) {
                    $callback($node, $scope);
                }

                return;
            }

            $this->recordedNodes -= count($recording['nodes']);
            unset($this->recordings[$key]);
        }

        // A plain pass, recording nothing, on two counts. No recording is ever built from inside another
        // walk — the nesting itself is the caller's business ("collect then recurse, never nest
        // processNodes" is Tracer's rule, and this pass IS a nested `processNodes` when a caller breaks it);
        // what the guard buys is that an outer recording cannot end up interleaved with an inner walk's
        // nodes. And a file already found oversized skips the accumulation it would only throw away again.
        if ($this->walking || isset($this->oversized[$key])) {
            $this->livePass($file, $callback);

            return;
        }

        $this->walking = true;

        /** @var RecordedWalk $recorded */
        $recorded = [];
        $abandoned = false;
        try {
            $this->livePass($file, function (Node $node, Scope $scope) use (&$recorded, &$abandoned, $callback): void {
                if (! $abandoned && $this->exhausted(count($recorded))) {
                    // Stop appending AND drop what was accumulated: holding a recording that will be
                    // discarded anyway is the peak-memory cost this budget exists to bound.
                    $abandoned = true;
                    $recorded = [];
                }
                if (! $abandoned) {
                    $recorded[] = [$node, $scope];
                }

                $callback($node, $scope);
            });

            // Only a walk that ran to the end is worth keeping: replaying a truncated recording would
            // answer a later consumer with less than a live pass gives it.
            if ($abandoned) {
                $this->oversized[$key] = true;
            } else {
                $this->store($key, $recorded);
            }
        } finally {
            $this->walking = false;
        }
    }

    /**
     * One live pass, stabilising each callback scope before anyone sees it. Deduped per scope object because
     * several nodes share one instance, which is what keeps stabilising every node's scope near-free. A
     * `WeakMap` rather than an `spl_object_id` array: PHPStan discards scopes as it walks, and a reused
     * object handle would hand a fresh scope the stabilisation of a dead one.
     */
    private function livePass(string $file, callable $callback): void
    {
        /** @var WeakMap<Scope, Scope> $stable */
        $stable = new WeakMap;

        $this->adapter->processFile($file, function (Node $node, Scope $scope) use ($stable, $callback): void {
            $callback($node, $stable[$scope] ??= $this->adapter->stableScope($scope));
        });
    }

    /**
     * Whether a recording of `$recorded` nodes so far has to stop. Node count is the cheap proxy; the real
     * risk is bytes, so usage against the process ceiling decides too — a recording is a speed optimisation
     * and must never be the reason a build runs out of memory.
     */
    private function exhausted(int $recorded): bool
    {
        return $recorded >= $this->nodeBudget
            || ($this->memoryCeiling !== null && memory_get_usage() >= $this->memoryCeiling);
    }

    /**
     * @param  RecordedWalk  $recorded
     */
    private function store(string $key, array $recorded): void
    {
        $nodes = count($recorded);
        if ($this->recordedNodes + $nodes > $this->nodeBudget) {
            // Clear everything rather than evict a file at a time: a cleared file is walked live and
            // re-recorded, so the cheapest reset is also a correct one, and the replay path is left with
            // no ordering to maintain.
            $this->recordings = [];
            $this->recordedNodes = 0;
        }

        // Stamped AFTER the pass: `processFile()` primes the file itself, so the set the recording answers
        // for is the one the pass left behind.
        $this->recordings[$key] = ['nodes' => $recorded, 'analysed' => $this->adapter->analysedFileCount()];
        $this->recordedNodes += $nodes;
    }

    /** The ceiling in force for this process. */
    private static function ceilingFromIni(): ?int
    {
        return self::ceiling((string) ini_get('memory_limit'));
    }

    /**
     * The usable share of a php.ini memory value, in bytes — or null when it is unlimited or not a shorthand,
     * in which case the node budget is the only bound. Parsed here rather than shared with the adapter
     * package's `MemoryLimit`, which the engine may not import.
     */
    private static function ceiling(string $value): ?int
    {
        $value = strtolower(trim($value));
        $unit = substr($value, -1);
        $scale = self::MEMORY_UNITS[$unit] ?? 1;
        $number = isset(self::MEMORY_UNITS[$unit]) ? substr($value, 0, -1) : $value;

        // An unlimited `-1`, an empty value and a figure too large to be a byte count all read as "no
        // ceiling to compare against" — a saturating cast would be a worse answer than none.
        if (! ctype_digit($number) || strlen($number) > self::MEMORY_MAX_DIGITS || (int) $number > intdiv(PHP_INT_MAX, $scale)) {
            return null;
        }

        return (int) ((int) $number * $scale * self::MEMORY_HEADROOM);
    }
}
