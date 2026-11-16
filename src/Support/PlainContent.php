<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;

/**
 * What a plain response sends for the content it is handed, as Laravel's `Response::setContent()` decides:
 * nothing (`''`, `null`, omitted) is no body; an array or anything Laravel morphs to JSON is JSON, labelled
 * `application/json` over any header; a view or other Renderable is rendered; any other scalar is sent as it
 * is, labelled `text/html` unless a header says otherwise. Symfony's own response morphs nothing. Content of
 * no one kind that may be morphed may be sent as either, so under any media type; any other is labelled as
 * text is. Every
 * spelling of a plain response — constructed, made by the factory, the helper or a facade, or handed to a
 * subclass's parent — reads its content here.
 *
 * @internal
 */
final class PlainContent
{
    public const EMPTY = 'empty';

    public const TEXT = 'text';

    public const JSON = 'json';

    public const RENDERED = 'rendered';

    /** Nothing provable: content of a type that may be any of the others. */
    public const UNREAD = 'unread';

    /** What Symfony labels a body nobody gave a `Content-Type`. */
    public const DEFAULT_MEDIA_TYPE = 'text/html';

    /** The label of content that may be sent as JSON or as text: any media type at all. */
    public const ANY_MEDIA_TYPE = '*/*';

    /** What `Response::shouldBeJson()` morphs, besides an array. */
    private const MORPHED = [
        'Illuminate\\Contracts\\Support\\Arrayable',
        'Illuminate\\Contracts\\Support\\Jsonable',
        'ArrayObject',
        'JsonSerializable',
        'stdClass',
    ];

    private const RENDERABLE = 'Illuminate\\Contracts\\Support\\Renderable';

    /**
     * What content of this type is sent as; null is content nothing can see (a spread).
     *
     * @param  bool  $morphs  whether the response is Laravel's, which morphs an array to JSON, or Symfony's, which does not
     * @return self::EMPTY|self::TEXT|self::JSON|self::RENDERED|self::UNREAD
     */
    public static function of(?Type $content, bool $morphs): string
    {
        if ($content === null) {
            return self::UNREAD;
        }

        if (self::isEmpty($content)) {
            return self::EMPTY;
        }

        if ($morphs && self::morphed($content)) {
            return self::JSON;
        }

        if ($morphs && (new ObjectType(self::RENDERABLE))->isSuperTypeOf($content)->yes()) {
            return self::RENDERED;
        }

        // An empty arm beside text is still a string on the wire, only a short one.
        $scalar = new UnionType([new StringType, new IntegerType, new FloatType]);

        return $scalar->isSuperTypeOf(TypeCombinator::removeNull($content))->yes() ? self::TEXT : self::UNREAD;
    }

    /**
     * Whether content of this type may be morphed to JSON, which Laravel relabels `application/json` over any
     * header stated; null is content nothing can see.
     */
    public static function mayBeJson(?Type $content, bool $morphs): bool
    {
        if (! $morphs || $content === null) {
            return $morphs;
        }

        if (! $content->isArray()->no()) {
            return true;
        }

        foreach (self::MORPHED as $class) {
            if (! (new ObjectType($class))->isSuperTypeOf($content)->no()) {
                return true;
            }
        }

        return false;
    }

    /** Whether content of this type is provably nothing — `''` or `null`, whichever it is. */
    public static function isEmpty(Type $type): bool
    {
        return $type->isConstantScalarValue()->yes()
            && array_filter($type->getConstantScalarValues(), static fn (mixed $value): bool => $value !== '' && $value !== null) === [];
    }

    /** The body text content of this type is sent as: the one string it folds to, or any string. */
    public static function text(Type $content): DType
    {
        // Content that may be null may be no body at all, so no one string is what it always sends.
        $folded = $content->isNull()->no() ? ScalarFold::of($content) : null;

        return $folded !== null && is_scalar($folded[0]) && ! is_bool($folded[0])
            ? new LiteralT((string) $folded[0])
            : ScalarT::string();
    }

    private static function morphed(Type $content): bool
    {
        if ($content->isArray()->yes()) {
            return true;
        }

        foreach (self::MORPHED as $class) {
            if ((new ObjectType($class))->isSuperTypeOf($content)->yes()) {
                return true;
            }
        }

        return false;
    }
}
