<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Analysis\LinkFacts;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Reflection\ReflectionProviderStaticAccessor;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * What a link of a fluent chain sets is what the server sends once it has run: `setContent()` and
 * `setData()` replace the body and nothing else, `setEncodingOptions()` changes the body only with a flag
 * that reshapes it, removing a header changes the media type only where it may be `Content-Type`,
 * `setNotModified()` sends a 304 with nothing, and a JSONP callback wraps the body and relabels it unless the callback is null. A
 * fact a link may have changed in a way nobody can read is unknown, never the one the response was built
 * with.
 */
const LINK_FACTS_PLAIN = 'Illuminate\\Http\\Response';
const LINK_FACTS_JSON = 'Illuminate\\Http\\JsonResponse';

/**
 * A link's facts, with comparing object types answered as a booted analyser would for a class reading itself,
 * and a JSON body read as an empty object wherever there is one.
 *
 * @param  Closure(Closure(Type): ?DType): array<string, mixed>  $read
 * @return array<string, mixed>
 */
function linkFactsOf(Closure $read): array
{
    $accessor = new ReflectionProperty(ReflectionProviderStaticAccessor::class, 'instance');
    $previous = $accessor->getValue();
    ReflectionProviderStaticAccessor::registerInstance(test()->createStub(ReflectionProvider::class));
    try {
        return $read(static fn (Type $type): ?DType => $type instanceof MixedType ? null : new ArrayShapeT([]));
    } finally {
        $accessor->setValue(null, $previous);
    }
}

/**
 * @param  array<string, mixed>  $facts
 * @return array<string, mixed>
 */
function linkFacts(array $facts): array
{
    return $facts + LinkFacts::untouched();
}

it('reads content as the constructor reads it, replacing the body and no status', function (?Type $content, string $root, array $facts): void {
    expect(linkFactsOf(static fn (Closure $payload): array => LinkFacts::content($content, $root, $payload)))->toEqual(linkFacts($facts));
})->with([
    'text' => [new ConstantStringType('accepted'), LINK_FACTS_PLAIN, ['body' => new LiteralT('accepted')]],
    'any string' => [new StringType, LINK_FACTS_PLAIN, ['body' => ScalarT::string()]],
    'nothing' => [new ConstantStringType(''), LINK_FACTS_PLAIN, ['body' => new VoidT]],
    'a view' => [new ObjectType('Illuminate\\Contracts\\Support\\Renderable'), LINK_FACTS_PLAIN, ['body' => new ClassT('Illuminate\\Contracts\\View\\View')]],
    // Laravel morphs an array to JSON and labels it so, over any header.
    'an array, morphed' => [new ArrayType(new MixedType, new MixedType), LINK_FACTS_PLAIN, ['body' => new ArrayShapeT([]), 'contentTypeUnknown' => true]],
    // Content that may be JSON may have been relabelled as JSON.
    'content of any type' => [new MixedType, LINK_FACTS_PLAIN, ['bodyUnknown' => true, 'contentTypeUnknown' => true]],
    'content nobody can see' => [null, LINK_FACTS_PLAIN, ['bodyUnknown' => true, 'contentTypeUnknown' => true]],
    // Symfony's response morphs nothing, and so relabels nothing.
    'content of any type on Symfony\'s response' => [new MixedType, 'Symfony\\Component\\HttpFoundation\\Response', ['bodyUnknown' => true]],
    // A JSON response sends the content as it is, which is no JSON body anyone can name.
    'text on a JSON response' => [new ConstantStringType('accepted'), LINK_FACTS_JSON, ['bodyUnknown' => true]],
]);

it('reads data as the constructor reads it, replacing the body and no status', function (?Type $data, array $facts): void {
    expect(linkFactsOf(static fn (Closure $payload): array => LinkFacts::data($data, $payload)))->toEqual(linkFacts($facts));
})->with([
    'data that reads' => [new ArrayType(new MixedType, new MixedType), ['body' => new ArrayShapeT([])]],
    'data that does not' => [new MixedType, ['bodyUnknown' => true]],
    'data nobody can see' => [null, ['bodyUnknown' => true]],
]);

it('keeps the body through an encoding unless a flag may reshape it', function (?Type $options, bool $reshaped): void {
    expect(LinkFacts::encoding($options))->toEqual(linkFacts(['bodyUnknown' => $reshaped]));
})->with([
    'pretty-printed' => [new ConstantIntegerType(JSON_PRETTY_PRINT), false],
    'unescaped, either way' => [new UnionType([new ConstantIntegerType(JSON_UNESCAPED_SLASHES), new ConstantIntegerType(JSON_UNESCAPED_UNICODE)]), false],
    // An empty list goes out as `{}`, a numeric string as a number.
    'JSON_FORCE_OBJECT' => [new ConstantIntegerType(JSON_PRETTY_PRINT | JSON_FORCE_OBJECT), true],
    'JSON_NUMERIC_CHECK on one path' => [new UnionType([new ConstantIntegerType(0), new ConstantIntegerType(JSON_NUMERIC_CHECK)]), true],
    'flags nobody can read' => [new MixedType, true],
    'flags nobody can see' => [null, true],
]);

it('reads a JSONP callback as a body and media type nobody can name, unless it is null', function (?Type $callback, bool $passed, bool $wraps): void {
    expect(LinkFacts::callback($callback, $passed))->toEqual(linkFacts($wraps ? ['bodyUnknown' => true, 'contentTypeUnknown' => true] : []));
})->with([
    'null' => [new NullType, true, false],
    'none passed' => [null, true, false],
    'one nobody can see' => [null, false, true],
    'a name' => [new ConstantStringType('handle'), true, true],
    'a name or null' => [new UnionType([new StringType, new NullType]), true, true],
]);

it('reads a removed header as the media type only where it may be Content-Type', function (?Type $names, bool $mediaType): void {
    expect(LinkFacts::withoutHeader($names))->toEqual(linkFacts($mediaType ? ['contentTypeUnknown' => true] : []));
})->with([
    'another header' => [new ConstantStringType('X-Queue'), false],
    'other headers' => [new ConstantArrayType([new ConstantIntegerType(0), new ConstantIntegerType(1)], [new ConstantStringType('X-Queue'), new ConstantStringType('ETag')]), false],
    'none' => [new ConstantArrayType([], []), false],
    'Content-Type, in any case' => [new ConstantStringType('content-TYPE'), true],
    'Content-Type among others' => [new ConstantArrayType([new ConstantIntegerType(0), new ConstantIntegerType(1)], [new ConstantStringType('X-Queue'), new ConstantStringType('Content-Type')]), true],
    'a name nobody can read' => [new StringType, true],
    'a list nobody can read' => [new ArrayType(new MixedType, new StringType), true],
    'a name nobody can see' => [null, true],
]);

it('reads a response marked not modified as a 304 with no body and no media type', function (): void {
    expect(LinkFacts::notModified())->toEqual(linkFacts(['status' => new LiteralT(304), 'body' => new VoidT, 'contentTypeUnknown' => true]));
});

it('reads a link that may have touched anything as touching everything', function (): void {
    expect(LinkFacts::unread())->toEqual(['status' => null, 'statusUnknown' => true, 'contentType' => null, 'contentTypeUnknown' => true, 'body' => null, 'bodyUnknown' => true])
        ->and(LinkFacts::unknownMediaType())->toEqual(linkFacts(['contentTypeUnknown' => true]));
});
