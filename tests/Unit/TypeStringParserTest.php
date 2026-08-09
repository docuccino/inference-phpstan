<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Types\ImportContext;
use Docuccino\Inference\PhpStan\Types\TypeStringParser;

/**
 * Covers every branch of the phpdoc type-grammar → {@see DType} mapping the attribute layer relies on
 * (`#[Response(type: '…')]` etc.). Silent misparses here mis-document a whole response body, so the
 * identifier-alias table, generic collection forms, array shapes, const literals, and the degradation
 * branches each get an assertion. Kind + salient payload is asserted per branch.
 */
function parseType(string $type): mixed
{
    return (new TypeStringParser)->parse($type);
}

it('maps every scalar identifier alias to its DType', function (string $type, string $expected): void {
    expect(parseType($type)::class)->toBe($expected);
})->with([
    // int family
    'int' => ['int', ScalarT::class],
    'integer' => ['integer', ScalarT::class],
    'positive-int' => ['positive-int', ScalarT::class],
    'negative-int' => ['negative-int', ScalarT::class],
    'non-negative-int' => ['non-negative-int', ScalarT::class],
    // string family
    'string' => ['string', ScalarT::class],
    'non-empty-string' => ['non-empty-string', ScalarT::class],
    'class-string' => ['class-string', ScalarT::class],
    'numeric-string' => ['numeric-string', ScalarT::class],
    // float family
    'float' => ['float', ScalarT::class],
    'double' => ['double', ScalarT::class],
    'number' => ['number', ScalarT::class],
    // bool family
    'bool' => ['bool', ScalarT::class],
    'boolean' => ['boolean', ScalarT::class],
    'true' => ['true', ScalarT::class],
    'false' => ['false', ScalarT::class],
    // null
    'null' => ['null', NullT::class],
]);

it('resolves the scalar kinds precisely', function (): void {
    expect(parseType('int'))->toEqual(ScalarT::int())
        ->and(parseType('string'))->toEqual(ScalarT::string())
        ->and(parseType('float'))->toEqual(ScalarT::float())
        ->and(parseType('bool'))->toEqual(ScalarT::bool());
});

it('degrades imprecise identifiers to a reasoned UnknownT', function (string $type, string $reason): void {
    $result = parseType($type);
    expect($result)->toBeInstanceOf(UnknownT::class)
        ->and($result->reason)->toBe($reason);
})->with([
    'array' => ['array', 'untyped array'],
    'iterable' => ['iterable', 'untyped array'],
    'list' => ['list', 'untyped array'],
    'mixed' => ['mixed', 'mixed'],
    'object' => ['object', 'object'],
    'void' => ['void', 'void'],
    'never' => ['never', 'never'],
    'callable' => ['callable', 'callable'],
    'scalar' => ['scalar', 'scalar'],
    'empty string' => ['', 'empty type string'],
]);

it('treats an unknown bareword as a class reference and strips a leading slash', function (): void {
    expect(parseType('App\\Models\\User'))->toEqual(new ClassT('App\\Models\\User'))
        ->and(parseType('\\App\\Models\\User'))->toEqual(new ClassT('App\\Models\\User'));
});

it('maps generic list and array forms to List/Map DTypes', function (): void {
    expect(parseType('list<int>'))->toEqual(new ListT(ScalarT::int()))
        ->and(parseType('non-empty-list<string>'))->toEqual(new ListT(ScalarT::string()))
        ->and(parseType('array<int>'))->toEqual(new ListT(ScalarT::int()))
        ->and(parseType('array<string, int>'))->toEqual(new MapT(ScalarT::string(), ScalarT::int()))
        ->and(parseType('non-empty-array<string, int>'))->toEqual(new MapT(ScalarT::string(), ScalarT::int()));
});

it('maps the square-bracket array shorthand to a List DType', function (): void {
    expect(parseType('int[]'))->toEqual(new ListT(ScalarT::int()));
});

it('degrades an over-parameterised array generic', function (): void {
    $result = parseType('array<int, string, bool>');
    expect($result)->toBeInstanceOf(UnknownT::class)
        ->and($result->reason)->toBe('untyped array');
});

it('keeps a user generic as a parameterised ClassT', function (): void {
    $result = parseType('Illuminate\\Support\\Collection<int, App\\Models\\User>');
    expect($result)->toBeInstanceOf(ClassT::class)
        ->and($result->fqcn)->toBe('Illuminate\\Support\\Collection')
        ->and($result->typeArgs)->toHaveCount(2)
        ->and($result->typeArgs[1])->toEqual(new ClassT('App\\Models\\User'));
});

it('maps a keyed array shape with optional and numeric keys', function (): void {
    $result = parseType('array{id: int, name?: string, 0: bool}');
    expect($result)->toBeInstanceOf(ArrayShapeT::class)
        ->and($result->fields)->toHaveCount(3);

    [$id, $name, $zero] = $result->fields;
    expect($id->key)->toBe('id')
        ->and($id->type)->toEqual(ScalarT::int())
        ->and($id->optional)->toBeFalse()
        ->and($name->key)->toBe('name')
        ->and($name->optional)->toBeTrue()
        ->and($zero->key)->toBe(0)
        ->and($zero->type)->toEqual(ScalarT::bool());
});

it('maps an unkeyed array shape to positional integer keys', function (): void {
    $result = parseType('array{int, string}');
    expect($result)->toBeInstanceOf(ArrayShapeT::class);
    [$first, $second] = $result->fields;
    expect($first->key)->toBe(0)
        ->and($second->key)->toBe(1);
});

it('maps const-expression literal types', function (): void {
    expect(parseType("'draft'"))->toEqual(new LiteralT('draft'))
        ->and(parseType('42'))->toEqual(new LiteralT(42))
        ->and(parseType('3.14'))->toEqual(new LiteralT(3.14))
        ->and(parseType('true'))->toEqual(ScalarT::bool());
});

it('maps nullable, union and intersection composites', function (): void {
    expect(parseType('?int'))->toEqual(UnionT::of([ScalarT::int(), new NullT]))
        ->and(parseType('int|string'))->toEqual(UnionT::of([ScalarT::int(), ScalarT::string()]));

    $intersection = parseType('Countable&Traversable');
    expect($intersection)->toBeInstanceOf(IntersectionT::class);
});

it('resolves unqualified class names against a file import context', function (): void {
    $imports = ImportContext::forFile(dirname(__DIR__).'/Fixtures/ImportSample.php');
    $parser = new TypeStringParser;

    // A union of unqualified short names is resolved through the file's `use` imports (one aliased).
    $union = $parser->parse('MfaChallengeData|Enrollment', $imports);
    expect($union)->toBeInstanceOf(UnionT::class);
    expect(array_map(static fn (ClassT $c): string => $c->fqcn, $union->members))
        ->toBe(['App\\Data\\MfaChallengeData', 'App\\Data\\MfaEnrollmentChallengeData']);

    // A name under an imported namespace prefix, a same-namespace name, and an absolute name.
    expect($parser->parse('Models\\User', $imports))->toEqual(new ClassT('App\\Models\\User'))
        ->and($parser->parse('LocalData', $imports))->toEqual(new ClassT('Docuccino\\Sample\\Http\\LocalData'))
        ->and($parser->parse('\\App\\Already\\Qualified', $imports))->toEqual(new ClassT('App\\Already\\Qualified'));
});

it('leaves short class names unqualified without an import context (back-compat)', function (): void {
    expect(parseType('MfaChallengeData'))->toEqual(new ClassT('MfaChallengeData'));
});
