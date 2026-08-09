<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Translation;

/**
 * Bounds the recursion of {@see TypeTranslator} so a pathological nested type
 * (deeply recursive generics/shapes) can never spin forever — it degrades to
 * `UnknownT('translation depth budget exhausted')` at the limit (design §5,
 * default depth 12). Immutable: `descend()` returns a fresh, shallower budget.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
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
