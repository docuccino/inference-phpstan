<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * The one reading of a `Content-Type` header written at a call site — as a key of a header array
 * (`new JsonResponse($body, 422, ['Content-Type' => '…'])`, `->withHeaders([...])`) or as the name
 * argument of `->header('Content-Type', '…')`. Two readers folding this differently would let a media
 * type depend on which spelling an app used, so both ask here.
 *
 * @internal
 */
final class ContentTypeHeader
{
    /** Header names are case-insensitive on the wire, and apps spell this one every which way. */
    public static function names(string $header): bool
    {
        return strcasecmp($header, 'content-type') === 0;
    }

    /**
     * The media type a header ARRAY states. Null when the expression isn't an array literal, when no key
     * folds to `Content-Type`, or when its value isn't a constant string — all of which mean "nothing
     * recovered here", never "no content type".
     */
    public static function inArray(Node\Expr $expr, Scope $scope): ?string
    {
        if (! $expr instanceof Node\Expr\Array_) {
            return null;
        }

        foreach ($expr->items as $item) {
            if ($item->key === null) {
                continue;
            }
            $key = ScalarFold::of($scope->getType($item->key));
            if ($key === null || ! is_string($key[0]) || ! self::names($key[0])) {
                continue;
            }
            $value = ScalarFold::of($scope->getType($item->value));
            if ($value !== null && is_string($value[0])) {
                return $value[0];
            }
        }

        return null;
    }
}
