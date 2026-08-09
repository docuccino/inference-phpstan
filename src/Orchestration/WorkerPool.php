<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Inference\PhpStan\Cache\EngineResultCache;
use Docuccino\Inference\PhpStan\Cache\NullEngineResultCache;
use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;

/**
 * The parent orchestrator (design §3). Given a set of {@see ActionRef}s it drives
 * K worker subprocesses to completion and returns their {@see ActionAnalysis}
 * results keyed by canonical action id — sorted, so scheduling never affects
 * output bytes.
 *
 * Responsibilities beyond dispatch:
 *   - cache front-door: ids already in the {@see EngineResultCache} skip the pool
 *     entirely; fresh results are stored (poison/fallback results are not cached);
 *   - recycling: workers self-exit after N actions or an RSS watermark and are
 *     respawned here;
 *   - failure containment: a worker that crashes or blows the per-action timeout
 *     has its still-unacknowledged actions re-queued as size-1 assignments to
 *     bisect the poison; an action that fails alone degrades to `UnknownT` + an
 *     error diagnostic while its siblings succeed;
 *   - boot-failure fallback: if the first worker reports it booted the
 *     {@see NullTypeEngine} (Larastan could not boot the app), the pool tears the
 *     workers down and finishes in-process, attaching one engine-level fatal
 *     diagnostic per action so docblock/attribute-only docs still build.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class WorkerPool
{
    public function __construct(
        private readonly OrchestrationConfig $config,
        private readonly EngineResultCache $cache = new NullEngineResultCache,
        private readonly ?VersionFingerprint $fingerprint = null,
    ) {}

    /**
     * @param  iterable<ActionRef>  $actions
     * @return array<string, ActionAnalysis> keyed by action id, sorted
     */
    public function analyze(iterable $actions): array
    {
        /** @var array<string, ActionRefLine> $lines */
        $lines = [];
        foreach ($actions as $action) {
            $line = new ActionRefLine($action);
            $lines[$line->id] = $line;
        }

        /** @var array<string, ActionAnalysis> $results */
        $results = [];

        // Cache front-door: serve hits, queue only the misses.
        /** @var list<string> $pending */
        $pending = [];
        foreach ($lines as $id => $line) {
            $hit = $this->fingerprint !== null
                ? $this->cache->getAction($line->ref, $this->fingerprint)
                : null;
            if ($hit !== null) {
                $results[$id] = $hit;
            } else {
                $pending[] = $id;
            }
        }

        if ($pending !== []) {
            $this->run($lines, $pending, $results);
        }

        ksort($results);

        return $results;
    }

    /**
     * @param  array<string, ActionRefLine>  $lines
     * @param  list<string>  $pending
     * @param  array<string, ActionAnalysis>  $results  (by-ref accumulator)
     */
    private function run(array $lines, array $pending, array &$results): void
    {
        /** @var list<list<string>> $queue */
        $queue = array_chunk($pending, max(1, $this->config->batchSize));

        $workers = $this->config->resolvedWorkers();
        /** @var list<WorkerSlot> $slots */
        $slots = [];
        for ($i = 0; $i < $workers; $i++) {
            $slots[] = new WorkerSlot;
        }

        $target = count($pending);
        $done = 0;

        while ($done < $target) {
            $progressed = false;

            foreach ($slots as $slot) {
                if ($this->drainSlot($slot, $lines, $results, $done)) {
                    $progressed = true;
                }

                if ($slot->bootFailed) {
                    $this->finishInProcess($lines, $queue, $slots, $results, $done);

                    return;
                }

                if ($this->assignSlot($slot, $lines, $queue)) {
                    $progressed = true;
                }

                if ($this->enforceTimeout($slot, $lines, $results, $done, $queue)) {
                    $progressed = true;

                    continue;
                }

                if ($this->reapSlot($slot, $lines, $results, $done, $queue)) {
                    $progressed = true;
                }
            }

            // Termination guard: nothing left to schedule but ids remain → poison them.
            if ($queue === [] && $this->allIdle($slots) && $done < $target) {
                $this->poisonUnaccounted($lines, $results, $done, 'orchestrator stalled with no worker able to make progress');
            }

            if (! $progressed) {
                usleep(1000);
            }
        }

        foreach ($slots as $slot) {
            $slot->worker?->stop();
        }
    }

    /**
     * @param  array<string, ActionRefLine>  $lines
     * @param  array<string, ActionAnalysis>  $results
     */
    private function drainSlot(WorkerSlot $slot, array $lines, array &$results, int &$done): bool
    {
        if ($slot->worker === null) {
            return false;
        }

        $progressed = false;
        foreach ($slot->worker->drain() as $message) {
            $type = $message['t'] ?? null;

            if ($type === WorkerProtocol::READY) {
                $slot->ready = true;
                if (($message['engine'] ?? null) === WorkerProtocol::ENGINE_NULL) {
                    $slot->bootFailed = true;
                }

                continue;
            }

            if ($type === WorkerProtocol::BYE) {
                // The worker is self-recycling; stop feeding it new work.
                $slot->retiring = true;

                continue;
            }

            if ($type !== WorkerProtocol::RESULT) {
                continue;
            }

            $id = $message['id'] ?? null;
            $analysis = $message['analysis'] ?? null;
            if (! is_string($id) || ! is_array($analysis) || ! in_array($id, $slot->assignment, true) || isset($slot->acked[$id])) {
                continue;
            }

            /** @var array<string, mixed> $analysis */
            $result = ActionAnalysis::fromArray($analysis);
            $results[$id] = $result;
            $done++;
            $slot->acked[$id] = true;
            $slot->actionStart = microtime(true);
            $progressed = true;

            if ($this->fingerprint !== null && isset($lines[$id])) {
                $this->cache->putAction($lines[$id]->ref, $result, $this->fingerprint);
            }
        }

        // A live worker that has acked its whole batch becomes idle and reassignable
        // (it may still self-exit to recycle — reapSlot handles that case).
        if ($slot->assignment !== [] && $slot->unacked() === []) {
            $slot->assignment = [];
            $slot->acked = [];
        }

        return $progressed;
    }

    /**
     * @param  array<string, ActionRefLine>  $lines
     * @param  list<list<string>>  $queue
     */
    private function assignSlot(WorkerSlot $slot, array $lines, array &$queue): bool
    {
        if ($slot->worker === null) {
            if ($queue === []) {
                return false; // no work — don't spawn idle workers.
            }
            $slot->worker = $this->spawn();

            return true;
        }

        if ($slot->retiring || ! $slot->ready || ! $slot->idle() || $queue === [] || ! $slot->worker->isRunning()) {
            return false;
        }

        $batch = array_shift($queue);
        $slot->assignment = $batch;
        $slot->acked = [];
        $slot->actionStart = microtime(true);

        $toSend = [];
        foreach ($batch as $id) {
            if (isset($lines[$id])) {
                $toSend[] = $lines[$id];
            }
        }
        $slot->worker->send(...$toSend);

        return true;
    }

    /**
     * @param  array<string, ActionRefLine>  $lines
     * @param  array<string, ActionAnalysis>  $results
     * @param  list<list<string>>  $queue
     */
    private function enforceTimeout(WorkerSlot $slot, array $lines, array &$results, int &$done, array &$queue): bool
    {
        if ($slot->worker === null || $slot->idle()) {
            return false;
        }

        $unacked = $slot->unacked();
        if ($unacked === [] || microtime(true) - $slot->actionStart <= $this->config->perActionTimeoutSeconds) {
            return false;
        }

        $slot->worker->stop();
        $this->contain($slot, $unacked, $lines, $results, $done, $queue, 'timeout');
        $slot->reset();

        return true;
    }

    /**
     * @param  array<string, ActionRefLine>  $lines
     * @param  array<string, ActionAnalysis>  $results
     * @param  list<list<string>>  $queue
     */
    private function reapSlot(WorkerSlot $slot, array $lines, array &$results, int &$done, array &$queue): bool
    {
        if ($slot->worker === null || $slot->worker->isRunning()) {
            return false;
        }

        $unacked = $slot->unacked();
        if ($unacked !== []) {
            // A clean exit (code 0) with work outstanding is a recycle-truncation —
            // the worker hit its budget mid-batch and never ran these; just re-run
            // them. Only a nonzero exit is a genuine crash.
            $this->contain($slot, $unacked, $lines, $results, $done, $queue, $slot->worker->exitCode() === 0 ? 'truncated' : 'crash');
        }

        $slot->reset();

        return true;
    }

    /**
     * Failure containment (design §3).
     *   - `truncated`: a benign recycle boundary — always re-queue, never poison.
     *   - `crash`/`timeout` on a size-1 assignment: the action is isolated and has
     *     defeated a whole worker — degrade it to a poison result so the build
     *     continues.
     *   - `crash`/`timeout` on a larger batch: re-queue each survivor as its own
     *     size-1 assignment, bisecting toward the poison action.
     *
     * @param  list<string>  $unacked
     * @param  array<string, ActionRefLine>  $lines
     * @param  array<string, ActionAnalysis>  $results
     * @param  list<list<string>>  $queue
     */
    private function contain(WorkerSlot $slot, array $unacked, array $lines, array &$results, int &$done, array &$queue, string $reason): void
    {
        $poisonable = $reason !== 'truncated' && count($slot->assignment) === 1;

        foreach ($unacked as $id) {
            if ($poisonable) {
                if (isset($lines[$id])) {
                    $results[$id] = $this->poison($lines[$id]->ref, $reason);
                    $done++;
                }

                continue;
            }

            $queue[] = [$id]; // bisect (crash) or simply re-run (truncation).
        }
    }

    /**
     * @param  array<string, ActionRefLine>  $lines
     * @param  array<string, ActionAnalysis>  $results
     */
    private function poisonUnaccounted(array $lines, array &$results, int &$done, string $reason): void
    {
        foreach ($lines as $id => $line) {
            if (! isset($results[$id])) {
                $results[$id] = $this->poison($line->ref, $reason);
                $done++;
            }
        }
    }

    /**
     * Boot-failure fallback: tear workers down, finish everything still pending
     * in-process (the {@see NullTypeEngine}, since the real boot failed) and tag
     * each with an engine-level fatal diagnostic.
     *
     * @param  array<string, ActionRefLine>  $lines
     * @param  list<list<string>>  $queue
     * @param  list<WorkerSlot>  $slots
     * @param  array<string, ActionAnalysis>  $results
     */
    private function finishInProcess(array $lines, array &$queue, array $slots, array &$results, int &$done): void
    {
        foreach ($slots as $slot) {
            $slot->worker?->stop();
            $slot->reset();
        }
        $queue = [];

        $fallback = new NullTypeEngine;
        foreach ($lines as $id => $line) {
            if (isset($results[$id])) {
                continue;
            }

            $base = $fallback->analyzeAction($line->ref);
            $results[$id] = new ActionAnalysis(
                returns: $base->returns,
                throws: $base->throws,
                diagnostics: [
                    new Diagnostic(
                        Severity::Error,
                        'inference.engine-boot-failed',
                        sprintf('Type engine failed to boot; %s documented from docblocks/attributes only.', $line->ref->symbol()),
                    ),
                    ...$base->diagnostics,
                ],
                dependencyFiles: $base->dependencyFiles,
            );
            $done++;
        }
    }

    private function poison(ActionRef $action, string $reason): ActionAnalysis
    {
        $label = match ($reason) {
            'timeout' => sprintf('exceeded the %.0fs per-action timeout', $this->config->perActionTimeoutSeconds),
            'crash' => 'crashed the worker process',
            'truncated' => 'produced no result before the worker exited',
            default => $reason,
        };

        return new ActionAnalysis(
            returns: [new ReturnSite(
                new UnknownT('analysis aborted: '.$label),
                new SourceLocation($action->file, $action->line),
            )],
            throws: [],
            diagnostics: [new Diagnostic(
                Severity::Error,
                'inference.action-poisoned',
                sprintf('Analysis of %s %s; isolated and degraded to unknown so the build continues.', $action->symbol(), $label),
            )],
            dependencyFiles: [$action->file],
        );
    }

    /**
     * @param  list<WorkerSlot>  $slots
     */
    private function allIdle(array $slots): bool
    {
        foreach ($slots as $slot) {
            if (! $slot->idle()) {
                return false;
            }
        }

        return true;
    }

    private function spawn(): Worker
    {
        $command = [
            $this->config->resolvedPhpBinary(),
            $this->config->resolvedWorkerScript(),
            $this->config->workerBootstrap,
            (string) $this->config->maxActionsPerWorker,
            (string) $this->config->rssLimitBytes,
        ];

        $worker = new Worker($command, $this->config->env);
        $worker->start();

        return $worker;
    }
}
