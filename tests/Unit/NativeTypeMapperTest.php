<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\CallableT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Metadata\NativeTypeMapper;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Colour;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\NativeTypeProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\NativeTypeProbeBase;

/**
 * The reflection-only type table behind `classMetadata()`'s property types — the one place a Data class's or
 * model's declared types become DTypes without a docblock in sight. It is a lookup table, so every entry is
 * driven, plus the shapes that degrade honestly (`array` with no value type, `iterable`, `object`, `mixed`,
 * `self`/`static`/`parent`, no declared type at all) and an unknown class name.
 *
 * Driven through REAL `ReflectionType` objects off {@see NativeTypeProbe}, never hand-built doubles: the
 * mapper's whole job is reading what PHP reflection actually hands back, and a double would let a wrong
 * assumption about that pass.
 */
function probeType(string $member): DType
{
    $mapper = new NativeTypeMapper;

    return str_ends_with($member, '()')
        ? $mapper->map((new ReflectionMethod(NativeTypeProbe::class, rtrim($member, '()')))->getReturnType())
        : $mapper->map((new ReflectionProperty(NativeTypeProbe::class, $member))->getType());
}

it('maps every native type it names', function (string $member, DType $expected): void {
    expect(probeType($member))->toEqual($expected);
})->with([
    'int' => ['int', ScalarT::int()],
    'string' => ['string', ScalarT::string()],
    'float' => ['float', ScalarT::float()],
    'bool' => ['bool', ScalarT::bool()],
    // `true`/`false` are types in their own right, and both are still a boolean on the wire.
    'true' => ['true', ScalarT::bool()],
    'false' => ['false', ScalarT::bool()],
    'null' => ['null()', new NullT],
    'void' => ['void()', new VoidT],
    'never' => ['never()', new NeverT],
    'callable' => ['callable()', new CallableT],
    'Closure' => ['closure', new CallableT],
    'a backed enum' => ['enum', new EnumT(Colour::class, ['Red', 'Green', 'Blue'])],
    'a plain class' => ['class', new ClassT(NativeTypeProbeBase::class)],
]);

it('degrades honestly for a type that names no shape', function (string $member, string $reason): void {
    // Each of these is a declared type that says nothing about the wire shape. The reason travels with the
    // UnknownT so a diagnostic can name it, rather than the mapper guessing an object or an empty array.
    $mapped = probeType($member);

    expect($mapped)->toBeInstanceOf(UnknownT::class)
        ->and($mapped->reason)->toBe($reason);
})->with([
    'an untyped array' => ['array', 'untyped array'],
    'iterable' => ['iterable', 'iterable'],
    'object' => ['object', 'object'],
    'mixed' => ['mixed', 'mixed'],
    // Of the relative class names, only `static` survives reflection unresolved — see below.
    'static' => ['static()', 'static'],
    'no declared type at all' => ['untyped', 'no declared type'],
]);

it('maps a relative class name however reflection reports it', function (): void {
    // PHP 8.5 resolves `self` and `parent` to the real FQCN before reflection reports them; 8.3 and 8.4 hand
    // them over verbatim, which is when the mapper's own `self`/`parent` labels fire. Both are correct — the
    // mapper maps what reflection said — so this pins each version rather than asserting one is the truth.
    // `static` reaches the mapper verbatim on every version, because it isn't knowable until the call.
    $resolved = PHP_VERSION_ID >= 80500;

    expect(probeType('self'))->toEqual($resolved ? new ClassT(NativeTypeProbe::class) : new UnknownT('self'))
        ->and(probeType('parent()'))->toEqual($resolved ? new ClassT(NativeTypeProbeBase::class) : new UnknownT('parent'));
});

it('folds nullability into a union, and never onto a type that already admits null', function (): void {
    expect(probeType('nullableString'))->toEqual(UnionT::of([ScalarT::string(), new NullT]))
        ->and(probeType('nullableEnum'))->toEqual(UnionT::of([new EnumT(Colour::class, ['Red', 'Green', 'Blue']), new NullT]))
        // `?string` is a union; `null` and `mixed` already include null, so wrapping them would say it twice.
        ->and(probeType('null()'))->toEqual(new NullT)
        ->and(probeType('mixed'))->toBeInstanceOf(UnknownT::class);
});

it('maps a union and an intersection through its own members', function (): void {
    expect(probeType('union'))->toEqual(UnionT::of([ScalarT::int(), ScalarT::string()]))
        ->and(probeType('intersection'))->toEqual(IntersectionT::of([new ClassT('Countable'), new ClassT('Stringable')]));
});
