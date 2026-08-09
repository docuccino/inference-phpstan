<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Parent-side handle to one worker subprocess: owns the {@see Process} and its streaming
 * {@see InputStream}, buffers stdout, hands back complete NDJSON messages. Lifecycle and framed IO only —
 * all scheduling state lives in {@see WorkerPool}.
 *
 * @internal
 */
final class Worker
{
    private ?Process $process = null;

    private ?InputStream $input = null;

    private string $buffer = '';

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function __construct(
        private readonly array $command,
        private readonly array $env = [],
    ) {}

    public function start(): void
    {
        $this->buffer = '';
        $this->input = new InputStream;

        // timeout: null — the parent does its own per-action wall-clock enforcement.
        $process = new Process($this->command, null, $this->env, null, null);
        $process->setInput($this->input);
        $process->start(function (string $type, string $data): void {
            if ($type === Process::OUT) {
                $this->buffer .= $data;
            }
        });

        $this->process = $process;
    }

    public function send(ActionRefLine ...$lines): void
    {
        if ($this->input === null || $this->process === null || ! $this->process->isRunning()) {
            return;
        }

        foreach ($lines as $line) {
            $this->input->write($line->encoded);
        }
    }

    /**
     * Pump the pipes and return every complete NDJSON message since the last drain; a partial trailing line
     * stays buffered.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        $this->process?->isRunning();

        $messages = [];
        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);

            $message = WorkerProtocol::decodeLine($line);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function isRunning(): bool
    {
        return $this->process !== null && $this->process->isRunning();
    }

    public function exitCode(): ?int
    {
        return $this->process?->getExitCode();
    }

    public function stop(): void
    {
        try {
            $this->input?->close();
        } catch (\Throwable) {
            // already closed with the process.
        }

        $this->process?->stop(0.1);
        $this->process = null;
        $this->input = null;
        $this->buffer = '';
    }
}
