<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

/**
 * ROLE: the value-flow provenance of one recovered value — the callee PARAMETER it reads from plus HOW
 * it reads it ({@see AccessorKind}). Binding at the call site consumes it.
 *
 * The folding arc it belongs to is documented ONCE, in `inference-embedding.md` §4a (step 2), with the
 * mechanics in {@see ResponseShapeRefiner}.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final readonly class ParamAccessor
{
    public function __construct(
        public string $param,
        public AccessorKind $kind = AccessorKind::Identity,
        public ?string $method = null,
    ) {}

    /** A plain pass-through of the parameter (the value IS the parameter, no accessor). */
    public static function identity(string $param): self
    {
        return new self($param, AccessorKind::Identity);
    }

    /** Re-home this accessor onto an OUTER parameter (transitive binding through a call hop). */
    public function withParam(string $param): self
    {
        return new self($param, $this->kind, $this->method);
    }

    /** Whether two accessors read the SAME value off the same parameter (status-marker matching). */
    public function equals(self $other): bool
    {
        return $this->param === $other->param
            && $this->kind === $other->kind
            && $this->method === $other->method;
    }
}
