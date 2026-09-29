<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Tests\Fixtures\ProblemDetails;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/*
 * The real engine's reading of a constructor, in an installed app. `json_encode` leaves out a typed
 * property nothing assigned, so the key is there exactly when every path the constructor completes by
 * assigned it: a branch not taken or an early `return` leaves it out, a path that throws builds no object
 * at all. Where the analyser does not track the property — one a parent declares and a replacing
 * constructor hands to `parent::__construct()`, or a class with no constructor — nothing is claimed.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('answers whether every completing constructor path assigns the property', function (string $class, array $expected): void {
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Problems\\'.$class));

    $initialised = [];
    foreach ($metadata->properties as $property) {
        $initialised[$property->name] = $property->initialised;
    }

    expect($initialised)->toBe($expected);
})->with([
    // A throwing guard ahead of the assignments ends no construction; `instance` is assigned even as null.
    'branches and a throwing guard' => ['ProblemDetails', ['type' => true, 'title' => true, 'status' => true, 'detail' => false, 'instance' => true, 'traceId' => false]],
    'an early return' => ['RetryNotice', ['message' => true, 'retryAfter' => false]],
    'a replacing constructor' => ['ConflictProblem', ['conflictsWith' => true, 'title' => null]],
    'an inherited constructor' => ['GenericProblem', ['title' => true]],
    'no constructor' => ['AssembledProblem', ['title' => null, 'status' => null]],
    // A helper the class declares is followed as PHP runs it: a key it always assigns is always sent, and one
    // it assigns only in a branch may be left out.
    'helpers the constructor calls, its own and a trait\'s' => ['HydratedProblem', ['type' => true, 'title' => true, 'status' => true, 'detail' => false, 'traceId' => false]],
    // What the analyser cannot follow may assign what the constructor's own paths skip, so a skip proves
    // nothing there — while what those paths do assign stays proved.
    'a dynamic write inside a helper' => ['FilledProblem', ['title' => null, 'status' => null]],
    'a parent constructor that assigns the subclass\'s members' => ['PaymentProblem', ['type' => true, 'title' => null, 'balance' => null]],
    // Only the class can run a private constructor, so its named constructors decide what an instance holds.
    'a named constructor assigning after a private one' => ['RateLimitProblem', ['title' => true, 'retryAfter' => null]],
])->group('fixture');

it('publishes what the constructor may leave unassigned as optional, and nothing else', function (): void {
    $real = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Problems\\ProblemDetails'));

    // The analysed class is the fixture app's; the one the mapper reflects is its in-process twin.
    $registry = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [ProblemDetails::class => new ClassMetadata(ProblemDetails::class, $real->properties)]);
    (new SchemaConverter(DefaultTypeMappers::all(), $engine, $registry))->toSchema(new ClassT(ProblemDetails::class));

    $schema = $registry->schemas()['ProblemDetails'];

    // `new ProblemDetails(404, 'Not Found')` sends `{"type","title","status","instance"}` and nothing more.
    expect($schema['required'])->toBe(['type', 'title', 'status', 'instance'])
        // Optional, and still not nullable: the server never sends `null` for it.
        ->and($schema['properties']['detail']['type'])->toBe('string')
        ->and($schema['properties']['instance'])->toBe(['type' => ['string', 'null']]);
})->group('fixture');
