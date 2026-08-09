<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

/**
 * How a {@see ParamAccessor} reads its value off the callee parameter: {@see Identity} is the parameter
 * unchanged, {@see Value}/{@see Name} an enum case's `->value`/`->name`, {@see Method} a no-arg accessor
 * method (`$problem->status()`). {@see EnumAccessorFolder} decides which of them actually fold.
 *
 * @internal
 */
enum AccessorKind
{
    case Identity;
    case Value;
    case Name;
    case Method;
}
