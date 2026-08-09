<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * One entry in the {@see KnownThrowers} registry: the exception a known
 * Laravel-semantic callee raises, plus how to derive its HTTP status —
 * either a fixed status, or by constant-folding a positional argument
 * (`abort($status)` = arg 0; `abort_if($cond, $status)` = arg 1).
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final readonly class KnownThrower
{
    private function __construct(
        public string $exceptionFqcn,
        public ?int $fixedStatus,
        public ?int $statusArgIndex,
    ) {}

    public static function withStatus(string $exceptionFqcn, int $status): self
    {
        return new self($exceptionFqcn, $status, null);
    }

    public static function withStatusFromArg(string $exceptionFqcn, int $argIndex): self
    {
        return new self($exceptionFqcn, null, $argIndex);
    }

    public function foldsStatusFromArgument(): bool
    {
        return $this->statusArgIndex !== null;
    }
}
