<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;

/**
 * The body of a worker process (design §3). It boots once (the engine is handed
 * in already constructed), announces readiness, then serves one {@see ActionRef}
 * per NDJSON request line, streaming back one result line each — flushed
 * immediately so the parent can track progress and enforce per-action timeouts.
 *
 * Self-recycling: the loop exits cleanly (emitting a `bye`) after `maxActions`
 * analyses or once `memory_get_usage(true)` crosses `rssLimitBytes` — the parent
 * respawns a fresh worker. A poison action that hard-crashes mid-analysis never
 * returns a result line; the parent detects the truncated stream and contains it.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class WorkerLoop
{
    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function __construct(
        private readonly TypeEngine $engine,
        private readonly int $maxActions,
        private readonly int $rssLimitBytes,
        private readonly mixed $in,
        private readonly mixed $out,
    ) {}

    public function run(): void
    {
        $this->write(WorkerProtocol::ready(
            $this->engine instanceof NullTypeEngine
                ? WorkerProtocol::ENGINE_NULL
                : WorkerProtocol::ENGINE_PHPSTAN,
        ));

        $processed = 0;

        while (($line = fgets($this->in)) !== false) {
            $message = WorkerProtocol::decodeLine($line);
            if ($message === null || ($message['t'] ?? null) !== WorkerProtocol::ACTION) {
                continue;
            }

            $ref = WorkerProtocol::actionRefFrom($message);
            if ($ref === null) {
                continue;
            }

            $id = is_string($message['id'] ?? null) ? $message['id'] : $ref->symbol();

            // May never return: a fatal here (the poison case) crashes the worker,
            // and the parent's containment takes over.
            $analysis = $this->engine->analyzeAction($ref);
            $this->write(WorkerProtocol::result($id, $analysis->toArray()));

            $processed++;
            if ($processed >= $this->maxActions) {
                $this->write(WorkerProtocol::bye('recycle'));

                return;
            }

            if (memory_get_usage(true) >= $this->rssLimitBytes) {
                $this->write(WorkerProtocol::bye('rss'));

                return;
            }
        }
    }

    private function write(mixed $message): void
    {
        fwrite($this->out, WorkerProtocol::encodeLine($message));
        fflush($this->out);
    }
}
