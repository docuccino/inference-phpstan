<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Runtime\RealTimeFacades;
use Docuccino\Inference\PhpStan\Tests\Support\FakeRealTimeLoader;
use Illuminate\Support\Facades\Facade;

/**
 * A real-time facade is a class the alias loader writes into the application's `storage/` and requires the
 * first time it is named, and PHPStan names it inside a trap that writes nothing and includes nothing, so the
 * analysis of the file that names it fails. Each is declared in memory instead, as the loader's stub declares
 * it: a facade whose root is whatever the container makes of the class it is named after.
 */
function realTimeAccessor(string $facade): mixed
{
    return (new ReflectionMethod($facade, 'getFacadeAccessor'))->invoke(null);
}

it('declares a real-time facade in memory as a facade of the class it is named after', function (): void {
    FakeRealTimeLoader::namespaceFacadesUnder('DocuccinoRealTimeProbe\\');

    expect(RealTimeFacades::serve(FakeRealTimeLoader::class))->toBeTrue()
        ->and(class_exists('DocuccinoRealTimeProbe\\ArrayObject'))->toBeTrue()
        ->and(is_subclass_of('DocuccinoRealTimeProbe\\ArrayObject', Facade::class))->toBeTrue()
        ->and(realTimeAccessor('DocuccinoRealTimeProbe\\ArrayObject'))->toBe(ArrayObject::class)
        ->and(realTimeAccessor('DocuccinoRealTimeProbe\\Docuccino\\Probe\\Ledger'))->toBe('Docuccino\\Probe\\Ledger')
        // Declared, not written: no file on disk holds it.
        ->and(is_file((string) (new ReflectionClass('DocuccinoRealTimeProbe\\ArrayObject'))->getFileName()))->toBeFalse();
});

it('takes the real-time facades off the loader, which then names none', function (): void {
    FakeRealTimeLoader::namespaceFacadesUnder('DocuccinoRealTimeHandover\\');
    RealTimeFacades::serve(FakeRealTimeLoader::class);

    expect(FakeRealTimeLoader::facadeNamespace())->toBe('')
        // Served once is served: a second boot in the same process finds nothing left to take.
        ->and(RealTimeFacades::serve(FakeRealTimeLoader::class))->toBeFalse()
        ->and(class_exists('DocuccinoRealTimeHandover\\ArrayIterator'))->toBeTrue();
});

it('declares nothing outside the real-time namespace, nor a name that is no class name', function (string $class): void {
    FakeRealTimeLoader::namespaceFacadesUnder('DocuccinoRealTimeNamed\\');
    RealTimeFacades::serve(FakeRealTimeLoader::class);

    expect(class_exists($class))->toBeFalse();
})->with([
    'another namespace' => ['DocuccinoRealTimeElsewhere\\ArrayObject'],
    'the namespace itself, naming no class' => ['DocuccinoRealTimeNamed\\'],
    'a segment no class name can have' => ['DocuccinoRealTimeNamed\\Array-Object'],
]);

it('serves nothing where the application has no alias loader to take them from', function (string $loader): void {
    expect(RealTimeFacades::serve($loader))->toBeFalse();
})->with([
    'no loader' => ['Docuccino\\Nowhere\\AliasLoader'],
    'a loader without real-time facades' => [ArrayObject::class],
]);
