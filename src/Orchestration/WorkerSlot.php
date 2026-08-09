<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

/**
 * Mutable per-worker scheduling state held by {@see WorkerPool}: the live {@see Worker} (null until spawned
 * and after a crash), its current assignment and which ids are acknowledged, whether the startup handshake
 * arrived, and when the in-flight action's clock started.
 *
 * @internal
 */
final class WorkerSlot
{
    public ?Worker $worker = null;

    public bool $ready = false;

    public bool $bootFailed = false;

    /** Set once the worker signals a self-recycle; no more work is fed to it. */
    public bool $retiring = false;

    /** @var list<string> */
    public array $assignment = [];

    /** @var array<string, true> */
    public array $acked = [];

    public float $actionStart = 0.0;

    public function reset(): void
    {
        $this->worker = null;
        $this->ready = false;
        $this->bootFailed = false;
        $this->retiring = false;
        $this->assignment = [];
        $this->acked = [];
        $this->actionStart = 0.0;
    }

    public function idle(): bool
    {
        return $this->assignment === [];
    }

    /**
     * @return list<string> assigned ids not yet acknowledged
     */
    public function unacked(): array
    {
        return array_values(array_filter(
            $this->assignment,
            fn (string $id): bool => ! isset($this->acked[$id]),
        ));
    }
}
