<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

/**
 * Where one recovered value comes from: the callee parameter it reads, plus how it reads it
 * ({@see AccessorKind}). Binding at the call site consumes it; mechanics in {@see ResponseShapeRefiner}.
 *
 * @internal
 */
final readonly class ParamAccessor
{
    public function __construct(
        public string $param,
        public AccessorKind $kind = AccessorKind::Identity,
        public ?string $method = null,
    ) {}

    /** A plain pass-through — the value is the parameter itself, no accessor. */
    public static function identity(string $param): self
    {
        return new self($param, AccessorKind::Identity);
    }

    /** Re-home onto an outer parameter, for transitive binding through a call hop. */
    public function withParam(string $param): self
    {
        return new self($param, $this->kind, $this->method);
    }

    /** Whether two accessors read the same value off the same parameter — status-marker matching. */
    public function equals(self $other): bool
    {
        return $this->param === $other->param
            && $this->kind === $other->kind
            && $this->method === $other->method;
    }
}
