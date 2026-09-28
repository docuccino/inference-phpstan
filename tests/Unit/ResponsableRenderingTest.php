<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\ResponsableRendering;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\InheritedResponseProbeData;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\InheritedResponseProbeResource;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\OwnResponseMethodProbeResource;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\OwnResponseProbeData;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\OwnResponseProbeResource;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\RenderingProbeResource;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\TraitResponseProbeData;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\TraitResponseProbeResource;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\VendorConcernProbeData;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Who answers a Responsable rendering itself. The router sends any returned Responsable through its
 * `toResponse()`, and `JsonResource::response()` is the framework calling that on the current request — so
 * the body of `toResponse()` that actually runs is the whole question, wherever it was written: in the
 * class, a base class or a trait. Laravel's own resource rendering is the resource's body, which the
 * adapter describes from the class; anyone else's is a method to read.
 */
it('names the author of the toResponse() a call runs', function (string $fqcn, string $method, ?string $owner): void {
    $isProject = static fn (string $file): bool => ! str_contains($file, '/vendor/');

    expect(ResponsableRendering::of(new ReflectionClass($fqcn), $method, $isProject))->toBe($owner);
})->with([
    'a resource the framework renders, ->toResponse()' => [RenderingProbeResource::class, 'toResponse', ResponsableRendering::FRAMEWORK],
    'a resource the framework renders, ->response()' => [RenderingProbeResource::class, 'response', ResponsableRendering::FRAMEWORK],
    'the framework\'s own collection' => [AnonymousResourceCollection::class, 'response', ResponsableRendering::FRAMEWORK],
    'the framework\'s own base resource' => [JsonResource::class, 'toResponse', ResponsableRendering::FRAMEWORK],
    'a resource writing toResponse(), called' => [OwnResponseProbeResource::class, 'toResponse', ResponsableRendering::APPLICATION],
    // The framework's response() is toResponse(), so the override is what it runs.
    'a resource writing toResponse(), reached by ->response()' => [OwnResponseProbeResource::class, 'response', ResponsableRendering::APPLICATION],
    'a base class writing it' => [InheritedResponseProbeResource::class, 'response', ResponsableRendering::APPLICATION],
    'an application trait writing it' => [TraitResponseProbeResource::class, 'toResponse', ResponsableRendering::APPLICATION],
    // A response() the application wrote is a helper of its own, not the framework's rendering.
    'a resource writing response()' => [OwnResponseMethodProbeResource::class, 'response', null],
    'a resource writing response(), ->toResponse()' => [OwnResponseMethodProbeResource::class, 'toResponse', ResponsableRendering::FRAMEWORK],
    'a Data class writing toResponse()' => [OwnResponseProbeData::class, 'toResponse', ResponsableRendering::APPLICATION],
    'a Data base class writing it' => [InheritedResponseProbeData::class, 'toResponse', ResponsableRendering::APPLICATION],
    'a Data class taking it from an application trait' => [TraitResponseProbeData::class, 'toResponse', ResponsableRendering::APPLICATION],
    // Spatie's own body is outside the resource family: nothing here can describe it, so it is left alone.
    'a Data class taking spatie\'s own' => [VendorConcernProbeData::class, 'toResponse', null],
    'response() on something that is not a resource' => [OwnResponseProbeData::class, 'response', null],
    'another method' => [RenderingProbeResource::class, 'toArray', null],
    'a class that is not Responsable' => [ArrayObject::class, 'toResponse', null],
]);

it('keys an answer on every project file an override could be added to', function (): void {
    // Whoever renders the object, a closer override written anywhere in its hierarchy — the class, a parent
    // between it and the one that wrote the answer, a trait — would change it. Vendor files never can.
    $isProject = static fn (string $file): bool => ! str_contains($file, '/vendor/');
    $files = static fn (string $fqcn): array => array_map('basename', ResponsableRendering::projectFiles(new ReflectionClass($fqcn), $isProject));

    expect($files(RenderingProbeResource::class))->toBe(['RenderingProbeResource.php'])
        ->and($files(InheritedResponseProbeResource::class))->toBe(['InheritedResponseProbeResource.php', 'BaseResponseProbeResource.php'])
        ->and($files(TraitResponseProbeResource::class))->toBe(['TraitResponseProbeResource.php', 'WritesProbeResourceResponse.php'])
        ->and($files(AnonymousResourceCollection::class))->toBe([]);
});
