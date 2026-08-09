<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

/**
 * ROLE: how a {@see ParamAccessor} reads its value off the callee parameter.
 *
 *   - {@see Identity}: the parameter unchanged (`$detail`, `$status`);
 *   - {@see Value} / {@see Name}: an enum-case parameter's `->value` / `->name`;
 *   - {@see Method}: a no-arg accessor method on an enum-case parameter (`$problem->status()`).
 *
 * Which of these fold, and under what containment rules, is documented ONCE in
 * `inference-embedding.md` §4a (steps 2-3); {@see EnumAccessorFolder} implements the enum cases.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
enum AccessorKind
{
    case Identity;
    case Value;
    case Name;
    case Method;
}
