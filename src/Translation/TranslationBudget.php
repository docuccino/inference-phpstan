<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Translation;

/**
 * Bounds {@see TypeTranslator}'s recursion so a pathologically nested type can't spin forever — at the limit
 * it degrades to `UnknownT('translation depth budget exhausted')`. Immutable: `descend()` returns a fresh,
 * shallower budget.
 *
 * @internal
 */
final readonly class TranslationBudget
{
    public const DEFAULT_DEPTH = 12;

    public function __construct(public int $remaining = self::DEFAULT_DEPTH) {}

    public function exhausted(): bool
    {
        return $this->remaining <= 0;
    }

    public function descend(): self
    {
        return new self($this->remaining - 1);
    }
}
