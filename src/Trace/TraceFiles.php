<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

/**
 * The two file sets a trace keeps, which answer two unrelated questions: what the walk can still afford
 * to OPEN, and what the fragment it feeds must invalidate on. Only an opened file is charged, so the
 * budget measures traversal cost and nothing else — which is what makes recording one more dependency
 * unable to make a trace recover less.
 *
 * @internal
 */
final class TraceFiles
{
    /** @var array<string, true> files the walk has opened — the budget's whole ledger */
    private array $opened = [];

    /** @var array<string, true> every opened file, plus every file one of their bodies was written in */
    private array $depended = [];

    public function __construct(private readonly int $budget) {}

    /**
     * Admit a file to the walk, unless that would exceed the per-analysis budget. An already-admitted
     * file always passes, and admitting one records it as a dependency for free.
     */
    public function admit(string $file): bool
    {
        if (isset($this->opened[$file])) {
            return true;
        }
        if (count($this->opened) >= $this->budget) {
            return false;
        }

        $this->opened[$file] = true;
        $this->depended[$file] = true;

        return true;
    }

    /**
     * Record a file a walked body was WRITTEN in. PHP hands a trait's method body to the walk of the
     * using class's file, so the trait decides what the route publishes while never being opened by name:
     * the walk that read it was charged already, and charging again would buy nothing but a shorter trace.
     */
    public function depend(string $file): void
    {
        $this->depended[$file] = true;
    }

    /**
     * Every file the trace read from — the ones it opened and the ones their bodies were written in.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->depended);
    }
}
