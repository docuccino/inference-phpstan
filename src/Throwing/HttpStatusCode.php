<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * Whether an integer the build folded is an HTTP status at all.
 *
 * Every status the analysis recovers ends up as a RESPONSE KEY in the emitted document, and a key outside
 * `100..599` is one no consumer can read: the OpenAPI response map keys on `1xx`–`5xx` (or `default`), so a
 * `parent::__construct(0, …)` or an `abort(-1)` that folded straight through would publish `"0"` and leave a
 * generated client failing or minting nonsense. The bound is the range the format itself allows rather than
 * the set IANA has assigned — an application's own `599` is a status the server really sends, and refusing
 * it would drop a real response.
 *
 * Refusing here is the vague-but-true answer: the throw is still surfaced, without a status of its own.
 *
 * @internal
 */
final class HttpStatusCode
{
    private const LOWEST = 100;

    private const HIGHEST = 599;

    /** The folded value where it is a status, and null — "nothing was read" — where it is not. */
    public static function folded(?int $value): ?int
    {
        return $value !== null && $value >= self::LOWEST && $value <= self::HIGHEST ? $value : null;
    }
}
