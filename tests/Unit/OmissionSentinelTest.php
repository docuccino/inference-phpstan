<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\OmissionSentinel;
use PHPStan\Type\ArrayType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * Which values render as an absent key rather than as a value. Everything here is a type the refiner really
 * gets back for a constructor argument, and each marker is the whole reason a member may be missing from a
 * body a client reads.
 */
it('recognises a value that can render as an omitted key', function (Type $type, bool $omits): void {
    expect(OmissionSentinel::inType($type))->toBe($omits);
})->with(function (): array {
    $optional = new ObjectType('Spatie\\LaravelData\\Optional');
    $lazy = new ObjectType('Spatie\\LaravelData\\Lazy');

    return [
        // What `$x ?? new Optional` types as once PHPStan has both arms.
        'string|Optional' => [new UnionType([new StringType, $optional]), true],
        'Optional alone' => [$optional, true],
        // The other marker spatie renders as absence, and the concrete class its factories hand back.
        'Lazy' => [$lazy, true],
        'a Lazy subclass' => [new ObjectType('Spatie\\LaravelData\\Support\\Lazy\\ConditionalLazy'), true],
        'list<string>|Lazy' => [new UnionType([new ArrayType(new IntegerType, new StringType), $lazy]), true],
        // A left side PHPStan proved non-null narrows the marker away, and the member is simply there.
        'a plain string' => [new StringType, false],
        // Nullable is not the same fact: the key is rendered, carrying null.
        'string|null' => [new UnionType([new StringType, new NullType]), false],
        // No object arm to inspect — and `mixed` must not be read as "might be a marker".
        'mixed' => [new MixedType, false],
        // An unrelated object, including one that cannot be reflected at all.
        'an unrelated class' => [new ObjectType('Illuminate\\Http\\JsonResponse'), false],
        'a class that does not exist' => [new ObjectType('App\\Nothing\\AtAll'), false],
    ];
});
