<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Runtime\FacadeAliases;
use Docuccino\Inference\PhpStan\Tests\Support\FakeAliasLoader;

/**
 * PHPStan finds a class by asking the autoloaders inside a trap that includes nothing, so an alias Laravel's
 * loader would declare on first use is never declared there, and `\Response::json(…)` reads as a call on no
 * class. Each alias the application's loader holds is declared up front instead, as the loader itself would.
 */
it('declares every alias the application holds as the class it names', function (): void {
    FakeAliasLoader::$aliases = ['DocuccinoAliasesProbeList' => ArrayObject::class, 'DocuccinoAliasesProbeIterator' => ArrayIterator::class];

    expect(FacadeAliases::declare(FakeAliasLoader::class))->toBe(2)
        ->and(class_exists('DocuccinoAliasesProbeList', false))->toBeTrue()
        ->and((new ReflectionClass('DocuccinoAliasesProbeList'))->getName())->toBe(ArrayObject::class)
        ->and((new ReflectionClass('DocuccinoAliasesProbeIterator'))->getName())->toBe(ArrayIterator::class);
});

it('leaves an alias that is already a class as it is', function (): void {
    FakeAliasLoader::$aliases = ['ArrayObject' => ArrayIterator::class];

    expect(FacadeAliases::declare(FakeAliasLoader::class))->toBe(0)
        ->and((new ReflectionClass('ArrayObject'))->getName())->toBe(ArrayObject::class);
});

it('leaves an alias to a class that does not exist undeclared, for the application to fail on where it is used', function (): void {
    FakeAliasLoader::$aliases = ['DocuccinoAliasesProbeGone' => 'Docuccino\\Nowhere\\Facade', 7 => ArrayObject::class, 'DocuccinoAliasesProbeOdd' => 7];

    expect(FacadeAliases::declare(FakeAliasLoader::class))->toBe(0)
        ->and(class_exists('DocuccinoAliasesProbeGone', false))->toBeFalse()
        ->and(class_exists('DocuccinoAliasesProbeOdd', false))->toBeFalse();
});

it('declares nothing where the application has no alias loader', function (): void {
    expect(FacadeAliases::declare('Docuccino\\Nowhere\\AliasLoader'))->toBe(0);
});
