<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

/**
 * The bound bookkeeping behind {@see ResponseShapeRefiner}'s descent: what the analysis in flight has
 * spent, what each memoised callee shape COST to compute, and whether a caller can afford to be handed one.
 *
 * A recovered shape is call-independent but NOT bound-independent, so the memo is gated in both
 * directions: a descent that hit a bound is never cached, and a cached one is only served to a caller with
 * the depth and file budget to have computed it itself. Without the second half a route's documented body
 * would depend on which unrelated route the build analysed first, and a warm build would replay a shape a
 * cold one cannot reach. File paths arrive normalised.
 *
 * @internal
 */
final class DescentBudget
{
    /**
     * What each memoised callee cost: the files its descent touched and the depth levels it used below
     * the callee.
     *
     * @var array<string, array{result: RefinedResponse|null, files: list<string>, depth: int}>
     */
    private array $entries = [];

    /** @var array<string, true> cycle guard over the descent (callee `class::method`). */
    private array $descending = [];

    /** @var array<string, true> files touched by the current analysis, drained by {@see takeFiles()}. */
    private array $files = [];

    /**
     * Every file touched this analysis, in order and with repeats. A descent's cost is the slice from
     * where it opened, which is what lets nested descents each record their own without a stack.
     *
     * @var list<string>
     */
    private array $touched = [];

    /** Bound hits so far, and how many of those a diagnostic has already reported. */
    private int $truncations = 0;

    private int $reported = 0;

    /** The deepest callee frame the descent in flight has reached. */
    private int $deepest = 0;

    public function __construct(
        private readonly int $maxDepth,
        private readonly int $fileBudget,
    ) {}

    /** Whether a callee frame at this depth is still inside the descent bound. */
    public function withinDepth(int $depth): bool
    {
        return $depth <= $this->maxDepth;
    }

    /** A file already touched costs nothing; a new one needs room left in the budget. */
    public function withinBudget(string $file): bool
    {
        return count($this->files) < $this->fileBudget || isset($this->files[$file]);
    }

    /** Land a file in the analysis's dependency set and on the log every open descent is costed against. */
    public function touch(string $file): void
    {
        $this->files[$file] = true;
        $this->touched[] = $file;
    }

    /**
     * Files touched since the last drain, for the analysis's `dependencyFiles`. Draining resets the
     * per-analysis state; the memo and its file sets outlive it. Nothing is mid-descent at a drain —
     * an analysis finishes before the next one starts — so the log resets with it.
     *
     * @return list<string>
     */
    public function takeFiles(): array
    {
        $files = array_keys($this->files);
        sort($files);
        $this->files = [];
        $this->touched = [];

        return $files;
    }

    /** A bound hit — whatever shape was being built around it is truncated. */
    public function truncate(): void
    {
        $this->truncations++;
    }

    /** Bound hits since the last drain, for the analysis's truncation diagnostic. */
    public function takeTruncations(): int
    {
        $unreported = $this->truncations - $this->reported;
        $this->reported = $this->truncations;

        return $unreported;
    }

    public function isDescending(string $key): bool
    {
        return isset($this->descending[$key]);
    }

    /**
     * The memoised shape for a callee reached at this depth, re-contributing what its descent touched — or
     * null when there is no entry or the caller could not have earned it, in which case the caller
     * recomputes and truncates honestly. The result comes back wrapped, so a memoised `null` shape is
     * distinguishable from a miss.
     *
     * @return array{RefinedResponse|null}|null
     */
    public function replay(string $key, int $depth): ?array
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null || ! $this->affordable($entry, $depth)) {
            return null;
        }

        foreach ($entry['files'] as $file) {
            $this->touch($file);
        }
        // The replayed levels count against whatever descent asked for them.
        $this->deepest = max($this->deepest, $depth + $entry['depth']);

        return [$entry['result']];
    }

    /**
     * Start recording what a callee's descent costs; the frame it returns goes back to {@see close()}.
     *
     * @return array{depth: int, truncations: int, deepest: int, touched: int}
     */
    public function open(string $key, int $depth): array
    {
        $this->descending[$key] = true;
        $frame = [
            'depth' => $depth,
            'truncations' => $this->truncations,
            'deepest' => $this->deepest,
            'touched' => count($this->touched),
        ];
        $this->deepest = $depth;

        return $frame;
    }

    /**
     * Finish a callee's descent, memoising it only when it stayed inside both bounds. A truncated one is
     * less refined depending on how much budget was already spent before that callee was reached, so
     * caching it would make output route-order dependent: it is used now and recomputed next time.
     *
     * @param  array{depth: int, truncations: int, deepest: int, touched: int}  $frame
     */
    public function close(string $key, array $frame, ?RefinedResponse $result): void
    {
        unset($this->descending[$key]);

        $used = $this->deepest - $frame['depth'];
        $this->deepest = max($frame['deepest'], $this->deepest);

        if ($this->truncations !== $frame['truncations']) {
            return;
        }

        $files = array_values(array_unique(array_slice($this->touched, $frame['touched'])));
        sort($files);
        $this->entries[$key] = ['result' => $result, 'files' => $files, 'depth' => $used];
    }

    /**
     * Whether serving this entry is the same answer the caller would have computed: enough depth left for
     * the levels the descent used, and enough file budget for every file it touched (one already touched
     * is free). A descent only ever adds files, so a union that fits also fits at every point along the way.
     *
     * @param  array{result: RefinedResponse|null, files: list<string>, depth: int}  $entry
     */
    private function affordable(array $entry, int $depth): bool
    {
        if (! $this->withinDepth($depth + $entry['depth'])) {
            return false;
        }

        $fresh = 0;
        foreach ($entry['files'] as $file) {
            if (! isset($this->files[$file])) {
                $fresh++;
            }
        }

        return count($this->files) + $fresh <= $this->fileBudget;
    }
}
