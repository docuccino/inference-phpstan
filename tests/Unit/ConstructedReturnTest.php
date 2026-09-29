<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Extensions\ConstructedReturn;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Constructed\BuildsOne;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Constructed\BuildsOther;

/*
 * The one class a method's body constructs on every path, read from source: the answer an untyped
 * `newCollection()` override gives where the docblock it inherits names only the framework's collection.
 * Real native reflection over real probe bodies; the analyser's own reflection hands the same method over
 * in the engine, which the fixture group's collection rows prove.
 */

// `BuildsOther` shares `BuildsOne`'s file, which is the point of it, so it loads with that file.
class_exists(BuildsOne::class);
it('reads the one class a body constructs on every path', function (string $class, string $method, string $built): void {
    expect((new ConstructedReturn)->of(new ReflectionMethod($class, $method)))->toBe($built);
})->with([
    'one construction' => [BuildsOne::class, 'one', SplObjectStorage::class],
    // Names resolve through the file's imports.
    'an imported alias' => [BuildsOne::class, 'imported', ArrayObject::class],
    'the same class on every path' => [BuildsOne::class, 'onEveryPath', SplObjectStorage::class],
    // A closure's return or a nested class's method is not the body's own.
    'returns inside a closure and a nested class' => [BuildsOne::class, 'nestedReturns', SplObjectStorage::class],
    // Reflection reports the `function` line, the parser the attribute's: the method is found by name.
    'a method with an attribute above it' => [BuildsOne::class, 'attributed', ArrayObject::class],
    // PHP reports a trait's method as the using class's; the trait's body is the one read.
    'a trait\'s method' => [BuildsOne::class, 'fromTrait', SplStack::class],
    // An alias exists only in the `use` clause; the trait writes the method under its own name.
    'a trait\'s method imported under an alias' => [BuildsOne::class, 'builtFromTrait', SplStack::class],
    // Same file, same method name, another class.
    'a same-named method of another class in the file' => [BuildsOther::class, 'one', ArrayIterator::class],
]);

it('reads a method an anonymous class declares, told apart from its same-named neighbour', function (): void {
    // An anonymous class has no name to look the method up under, so it is found by where it is written.
    [$first, $second] = BuildsOther::anonymous();

    expect((new ConstructedReturn)->of(new ReflectionMethod($first, 'one')))->toBe(SplDoublyLinkedList::class)
        ->and((new ConstructedReturn)->of(new ReflectionMethod($second, 'one')))->toBe(SplMinHeap::class);
});

it('gives no answer where no one class is constructed on every path', function (string $method): void {
    expect((new ConstructedReturn)->of(new ReflectionMethod(BuildsOne::class, $method)))->toBeNull();
})->with([
    'two classes by path' => ['twoClasses'],
    'a return that constructs nothing' => ['notConstructed'],
    // `static` is whichever class the call is bound to, which the source cannot say.
    'a late-static construction' => ['lateStatic'],
    'a class chosen at runtime' => ['dynamic'],
    'an anonymous class' => ['anonymous'],
    'no return at all' => ['returnsNothing'],
    'no body' => ['declaredOnly'],
]);

it('gives no answer for a method with no source to read', function (): void {
    // An internal method has no file, and an eval()'d one a file name that is no file.
    if (! class_exists('Docuccino\Inference\PhpStan\Tests\Unit\Evaluated\Built', false)) {
        eval('namespace Docuccino\Inference\PhpStan\Tests\Unit\Evaluated; final class Built { public function make(): object { return new \ArrayObject; } }');
    }

    expect((new ConstructedReturn)->of(new ReflectionMethod(ArrayObject::class, 'count')))->toBeNull()
        ->and((new ConstructedReturn)->of(new ReflectionMethod('Docuccino\Inference\PhpStan\Tests\Unit\Evaluated\Built', 'make')))->toBeNull();
});

it('answers for every method of a file it has already read', function (): void {
    $bodies = new ConstructedReturn;

    expect($bodies->of(new ReflectionMethod(BuildsOne::class, 'one')))->toBe(SplObjectStorage::class)
        ->and($bodies->of(new ReflectionMethod(BuildsOther::class, 'one')))->toBe(ArrayIterator::class)
        ->and($bodies->of(new ReflectionMethod(BuildsOne::class, 'imported')))->toBe(ArrayObject::class);
});
