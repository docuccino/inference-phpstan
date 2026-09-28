<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;

/**
 * Where `response()->json($data, $status)` and `response()->noContent($status)` take their body and status —
 * read once, for both readers of those calls: the return-type extension that types them and the refiner that
 * records a status forwarded from a helper's parameter. Two readers of one grammar are one reader, or the
 * guard stops matching the fold. Positional or named, and a spread nobody can read leaves a slot it covers
 * UNKNOWN rather than absent ({@see ArgumentSlots}).
 *
 * @internal
 */
final class ResponseFactoryCall
{
    /** The contract `response()` is typed as at the call site. */
    public const CONTRACT = 'Illuminate\\Contracts\\Routing\\ResponseFactory';

    public const JSON = 'json';

    public const NO_CONTENT = 'noContent';

    private const DEFAULT_STATUS = [self::JSON => 200, self::NO_CONTENT => 204];

    /** Where each method's signature takes the status. */
    private const STATUS_POSITION = [self::JSON => 1, self::NO_CONTENT => 0];

    /**
     * The call's body and status arguments, and whether a status it wrote nothing for is provably the
     * framework's default; null for any other call, a dynamic name, or a first-class callable — which has a
     * placeholder where its arguments go.
     *
     * @return array{method: string, body: Node\Expr|null, status: Node\Expr|null, defaultStatus: int|null}|null
     */
    public static function arguments(Node\Expr\MethodCall $call): ?array
    {
        if (! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
            return null;
        }

        // PHP method names are case-insensitive; the canonical spelling is what the rest reads.
        $method = match ($call->name->toLowerString()) {
            'json' => self::JSON,
            'nocontent' => self::NO_CONTENT,
            default => null,
        };
        if ($method === null) {
            return null;
        }

        $slots = ArgumentSlots::of($call->getArgs());
        $position = self::STATUS_POSITION[$method];

        return [
            'method' => $method,
            'body' => $method === self::JSON ? ($slots->at(0) ?? $slots->at('data')) : null,
            'status' => $slots->at($position) ?? $slots->at('status'),
            'defaultStatus' => $slots->knows($position) ? self::DEFAULT_STATUS[$method] : null,
        ];
    }
}
