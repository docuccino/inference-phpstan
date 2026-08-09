<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

use Docuccino\Core\Inference\TypeEngine;

/**
 * Tuning for the parent orchestrator and its workers (design §3).
 *
 * The `workerBootstrap` is a host-supplied PHP file that sets up autoloading and
 * returns a constructed {@see TypeEngine}; each worker
 * `require`s it once at startup (see `bin/worker.php`). Keeping bootstrap out of
 * the package is what lets the same worker binary run in any host app.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final readonly class OrchestrationConfig
{
    /**
     * @param  string  $workerBootstrap  absolute path to the PHP file returning a TypeEngine
     * @param  int  $workers  K — parallel worker processes
     * @param  int  $maxActionsPerWorker  N — recycle a worker after this many analyses
     * @param  int  $rssLimitBytes  recycle a worker once its heap crosses this watermark
     * @param  float  $perActionTimeoutSeconds  parent-enforced wall-clock budget per action
     * @param  int  $batchSize  actions assigned to an idle worker at once (bisected to 1 on failure)
     * @param  string|null  $phpBinary  interpreter for workers; defaults to the parent's PHP_BINARY
     * @param  string|null  $workerScript  the worker entrypoint; defaults to the package's bin/worker.php
     * @param  array<string, string>  $env  extra environment for worker processes
     */
    public function __construct(
        public string $workerBootstrap,
        public int $workers = 0,
        public int $maxActionsPerWorker = 50,
        public int $rssLimitBytes = 1_073_741_824,
        public float $perActionTimeoutSeconds = 60.0,
        public int $batchSize = 8,
        public ?string $phpBinary = null,
        public ?string $workerScript = null,
        public array $env = [],
    ) {}

    /**
     * K default = min(cores - 1, 8), floored at 1 (design §3).
     */
    public function resolvedWorkers(): int
    {
        if ($this->workers > 0) {
            return $this->workers;
        }

        return max(1, min(self::cpuCount() - 1, 8));
    }

    public function resolvedPhpBinary(): string
    {
        return $this->phpBinary ?? PHP_BINARY;
    }

    public function resolvedWorkerScript(): string
    {
        return $this->workerScript ?? dirname(__DIR__, 2).'/bin/worker.php';
    }

    private static function cpuCount(): int
    {
        $nproc = @shell_exec('nproc 2>/dev/null');
        if (is_string($nproc) && ($n = (int) trim($nproc)) > 0) {
            return $n;
        }

        $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if (is_string($sysctl) && ($n = (int) trim($sysctl)) > 0) {
            return $n;
        }

        return 1;
    }
}
