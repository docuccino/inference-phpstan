<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * One {@see KnownThrowers} entry: the exception a Laravel-semantic callee raises, plus how to get its HTTP
 * status — a fixed value, or by folding a positional argument (`abort($status)` is arg 0,
 * `abort_if($cond, $status)` is arg 1).
 *
 * @internal
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
