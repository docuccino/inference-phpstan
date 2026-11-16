<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Support\ContentTypeHeader;
use Docuccino\Inference\PhpStan\Support\PlainContent;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;

/**
 * What one link of a fluent chain sets of the three facts the document carries — the status, the media type
 * and the body — read off the types of what it is handed ({@see FluentResponseChain}). A fact a link cannot
 * touch is left as it was; one it may have touched in a way nobody can read is unknown.
 *
 * @phpstan-type ChainLink array{status: LiteralT|UnionT|null, statusUnknown: bool, contentType: string|null, contentTypeUnknown: bool, body: DType|null, bodyUnknown: bool}
 *
 * @internal
 */
final class LinkFacts
{
    /** The encoding flags that change what a JSON body is: an empty list sent as an object, a numeric string as a number. */
    private const RESHAPING_FLAGS = JSON_FORCE_OBJECT | JSON_NUMERIC_CHECK;

    /**
     * `setContent($content)`, read as the response's constructor reads its content ({@see PlainContent}): text,
     * nothing, a rendered view, or — for Laravel's plain response, which morphs it — JSON, relabelled with the
     * default media type. A JSON response sends content as it is, which is no JSON body this can name.
     *
     * @param  ?Type  $content  null where nobody can see it
     * @param  string  $root  the framework response the receiver is or derives from
     * @param  Closure(Type): ?DType  $payloadIn  how a JSON body is read
     * @return ChainLink
     */
    public static function content(?Type $content, string $root, Closure $payloadIn): array
    {
        $morphs = $root === ResponseShapeRefiner::PLAIN_RESPONSE;
        $read = $content === null || in_array($root, ResponseShapeRefiner::JSON_FQCNS, true) ? PlainContent::UNREAD : PlainContent::of($content, $morphs);
        $body = match (true) {
            $content === null => null,
            $read === PlainContent::EMPTY => new VoidT,
            $read === PlainContent::RENDERED => new ClassT(ResponseShapeRefiner::VIEW),
            $read === PlainContent::TEXT => PlainContent::text($content),
            $read === PlainContent::JSON => $payloadIn($content),
            default => null,
        };

        // Content that is JSON is relabelled as JSON, and content that may be has an unknown label.
        $relabelled = $read === PlainContent::JSON || ($read === PlainContent::UNREAD && $morphs);

        return ['body' => $body, 'bodyUnknown' => $body === null, 'contentTypeUnknown' => $relabelled] + self::untouched();
    }

    /**
     * `setData($rows)`: the body is the data now, read as the constructor reads it.
     *
     * @param  ?Type  $data  null where nobody can see it
     * @param  Closure(Type): ?DType  $payloadIn
     * @return ChainLink
     */
    public static function data(?Type $data, Closure $payloadIn): array
    {
        $body = $data === null ? null : $payloadIn($data);

        return ['body' => $body, 'bodyUnknown' => $body === null] + self::untouched();
    }

    /**
     * `setEncodingOptions($flags)` re-encodes the same data: a body still sent as it was, unless the flags may
     * reshape it ({@see RESHAPING_FLAGS}).
     *
     * @return ChainLink
     */
    public static function encoding(?Type $options): array
    {
        $flags = $options === null ? null : ScalarFold::ints($options);
        $kept = $flags !== null && array_filter($flags, static fn (int $each): bool => ($each & self::RESHAPING_FLAGS) !== 0) === [];

        return ['bodyUnknown' => ! $kept] + self::untouched();
    }

    /**
     * `setCallback($name)`: a JSONP body labelled as JavaScript, unless the name is provably null, which
     * changes nothing.
     *
     * @param  ?Type  $callback  null where none was passed (`$passed`) or nobody can see it
     * @param  bool  $passed  whether a call passing nothing is provably what this is
     * @return ChainLink
     */
    public static function callback(?Type $callback, bool $passed): array
    {
        if ($callback === null ? $passed : $callback->isNull()->yes()) {
            return self::untouched();
        }

        return ['bodyUnknown' => true, 'contentTypeUnknown' => true] + self::untouched();
    }

    /**
     * `withoutHeader($names)`: nothing documented, unless one of the names removed may be `Content-Type`.
     *
     * @param  ?Type  $names  a name or a list of them; null where nobody can see it
     * @return ChainLink
     */
    public static function withoutHeader(?Type $names): array
    {
        $arrays = $names?->getConstantArrays() ?? [];
        $values = $arrays === [] ? ($names === null ? [] : [$names]) : array_merge(...array_map(static fn (ConstantArrayType $array): array => $array->getValueTypes(), $arrays));
        $strings = [];
        foreach ($values as $value) {
            $constants = $value->getConstantStrings();
            if ($constants === [] || ! $value->isString()->yes()) {
                return self::unknownMediaType();
            }
            $strings = [...$strings, ...array_map(static fn (ConstantStringType $each): string => $each->getValue(), $constants)];
        }

        return $names === null || array_filter($strings, ContentTypeHeader::names(...)) !== [] ? self::unknownMediaType() : self::untouched();
    }

    /**
     * `setNotModified()`: a 304 with no body, its media type removed.
     *
     * @return ChainLink
     */
    public static function notModified(): array
    {
        return ['status' => new LiteralT(304), 'body' => new VoidT, 'contentTypeUnknown' => true] + self::untouched();
    }

    /**
     * A header link that may or may not have set the media type. Neither publishing the receiver's nor
     * inventing one is honest, so the chain reports it unknown and the response falls back to the default.
     *
     * @return ChainLink
     */
    public static function unknownMediaType(): array
    {
        return ['contentTypeUnknown' => true] + self::untouched();
    }

    /**
     * A link that touched no fact the document carries.
     *
     * @return ChainLink
     */
    public static function untouched(): array
    {
        return ['status' => null, 'statusUnknown' => false, 'contentType' => null, 'contentTypeUnknown' => false, 'body' => null, 'bodyUnknown' => false];
    }

    /**
     * A link that may have touched every fact: what the response was built with no longer stands, and only
     * its class does.
     *
     * @return ChainLink
     */
    public static function unread(): array
    {
        return ['status' => null, 'statusUnknown' => true, 'contentType' => null, 'contentTypeUnknown' => true, 'body' => null, 'bodyUnknown' => true];
    }
}
