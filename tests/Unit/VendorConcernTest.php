<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Extensions\DataToResponseReturnTypeExtension;
use Docuccino\Inference\PhpStan\Extensions\DataTransformReturnTypeExtension;
use Docuccino\Inference\PhpStan\Extensions\VendorConcern;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\InheritedResponseProbeData;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\OwnResponseProbeData;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\TraitResponseProbeData;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\VendorConcernProbeData;

/**
 * The two Data extensions claim a call's type only while spatie's own body is what runs, and hand the
 * call back to the refiner the moment an application has written its own. What decides that is which
 * FILE the body is declared in — spatie supplies `toResponse()` from `Concerns\ResponsableData` and
 * `transform()` from `Concerns\TransformableData`, and a body declared anywhere else is somebody else's.
 *
 * The rule stated from the contract rather than read back off the code: PHP flattens a trait into the
 * class that uses it, so a trait-supplied method reports the TRAIT's file while naming the using class as
 * its declarer. That is true of spatie's concern and equally true of an application's own trait, which is
 * the pair below that only a by-name test separates. Inheritance is not that pair: an override on a base
 * class is reported as declared BY that base class, so its method and its class agree on a file, and both
 * readings decline. Its rows are here as a regression guard, not as evidence of a defect.
 */
it('recognises only the concern\'s own body as the vendor\'s', function (string $fqcn, string $method, bool $vendor): void {
    $trait = $method === 'toResponse'
        ? 'Spatie\\LaravelData\\Concerns\\ResponsableData'
        : 'Spatie\\LaravelData\\Concerns\\TransformableData';

    expect(VendorConcern::provides(new ReflectionClass($fqcn), $trait, $method))->toBe($vendor);
})->with([
    'the concern supplies it' => [VendorConcernProbeData::class, 'toResponse', true],
    'the concern supplies transform()' => [VendorConcernProbeData::class, 'transform', true],
    'the class writes its own' => [OwnResponseProbeData::class, 'toResponse', false],
    'the class writes its own transform()' => [OwnResponseProbeData::class, 'transform', false],
    // The pair the vendor's own body is indistinguishable from until the concern is named.
    'an application trait writes it' => [TraitResponseProbeData::class, 'toResponse', false],
    'an application trait writes transform()' => [TraitResponseProbeData::class, 'transform', false],
    'a base class writes it' => [InheritedResponseProbeData::class, 'toResponse', false],
    'a base class writes transform()' => [InheritedResponseProbeData::class, 'transform', false],
]);

it('answers no for a trait that is not installed, rather than matching on absence', function (): void {
    expect(VendorConcern::provides(new ReflectionClass(VendorConcernProbeData::class), 'Vendor\\Gone\\Concern', 'toResponse'))
        ->toBeFalse()
        ->and(VendorConcern::provides(new ReflectionClass(VendorConcernProbeData::class), 'Spatie\\LaravelData\\Concerns\\ResponsableData', 'noSuchMethod'))
        ->toBeFalse();
});

it('takes every path it compares from the reflection it was handed', function (): void {
    // Stated in prose on the class, so it owes a test: the analyser spells a vendor file as composer's
    // autoloader recorded it (`vendor/composer/../spatie/…`) while PHP spells it canonically, so a file
    // looked up through PHP's own reflection never string-matches one the analyser reported. Reaching for
    // `new ReflectionClass` here is that bug, and it is invisible to every test in this file, which runs
    // under one provider. The real-engine rows in DataResponseShapeTest are what caught it.
    $source = (string) file_get_contents((string) (new ReflectionClass(VendorConcern::class))->getFileName());

    expect($source)->not->toContain('new ReflectionClass')
        ->and($source)->not->toContain('trait_exists')
        // Anti-vacuity: a scan that stopped seeing the file would pass both of those.
        ->and($source)->toContain('getTraits()')
        ->and(strlen($source))->toBeGreaterThan(1000);
});

it('leaves both Data extensions with no vendor test of their own', function (string $fqcn): void {
    // The wiring half, read from source rather than driven: PHPStan's ClassReflection is final, and a
    // double that redeclares a vendor type is the shape docs/design/defect-classes.md warns about. So
    // what is asserted here is that neither extension still decides this for itself — a `getFileName()`
    // inside one is the defect coming back, and the answer it would give is the one proven above.
    $source = (string) file_get_contents((string) (new ReflectionClass($fqcn))->getFileName());

    expect($source)->toContain('VendorConcern::provides(')
        ->and($source)->not->toContain('getFileName()')
        // Anti-vacuity: a scan that stopped seeing the file at all would pass both of those.
        ->and(strlen($source))->toBeGreaterThan(1000);
})->with([
    DataToResponseReturnTypeExtension::class,
    DataTransformReturnTypeExtension::class,
]);
