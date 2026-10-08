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
 * at all. The analyser tracks a property only in its own class's constructor, so one a parent declares is
 * answered by the parent's constructor where every completing path runs `parent::__construct()`; where
 * that call may be skipped, or for a class with no constructor, nothing is claimed.
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
    // The parent's own paths answer for what it declares, through every level of the hierarchy.
    'a replacing constructor' => ['ConflictProblem', ['conflictsWith' => true, 'title' => true]],
    'a parent constructor that may skip a member' => ['InstanceProblem', ['instance' => true, 'type' => true, 'title' => true, 'status' => true, 'detail' => false]],
    'an open class between them' => ['ApiProblem', ['instance' => true, 'type' => true, 'title' => true, 'status' => true, 'detail' => false]],
    'a grandparent constructor that may skip a member' => ['TracedProblem', ['traceId' => true, 'instance' => true, 'type' => true, 'title' => true, 'status' => true, 'detail' => false]],
    'a member assigned after the parent constructor' => ['ExplainedProblem', ['type' => true, 'title' => true, 'status' => true, 'detail' => true]],
    // A path that never runs the parent's constructor builds an object it says nothing about.
    'a parent constructor run in a branch' => ['DeferredProblem', ['type' => null, 'title' => null, 'status' => null, 'detail' => null]],
    'a return ahead of the parent constructor' => ['EarlyReturnProblem', ['type' => null, 'title' => null, 'status' => null, 'detail' => null]],
    'a parent constructor that writes members by name' => ['LockedProblem', ['title' => null, 'detail' => null]],
    // The class being built overrides the helper the analysed constructor calls, so that body is not the one
    // PHP runs — while the class declaring it, built as itself, is answered as before.
    'a helper the subclass overrides' => ['SilentNotice', ['title' => null, 'detail' => null]],
    'the helper as the base declares it' => ['NoticeProblem', ['title' => true, 'detail' => true]],
    'an inherited constructor' => ['GenericProblem', ['title' => true]],
    'no constructor' => ['AssembledProblem', ['title' => null, 'status' => null]],
    // A helper the class declares is followed as PHP runs it: a key it always assigns is always sent, and one
    // it assigns only in a branch may be left out.
    'helpers the constructor calls, its own and a trait\'s' => ['HydratedProblem', ['type' => true, 'title' => true, 'status' => true, 'detail' => false, 'traceId' => false]],
    // What the analyser cannot follow may assign what the constructor's own paths skip, so a skip proves
    // nothing there — while what those paths do assign stays proved.
    'a dynamic write inside a helper' => ['FilledProblem', ['title' => null, 'status' => null]],
    'a parent constructor that assigns the subclass\'s members' => ['PaymentProblem', ['type' => true, 'title' => null, 'balance' => null]],
    // What the parent assigns stays assigned — readonly cannot be written again — but a member its paths may
    // skip can still be filled by name afterwards.
    'a dynamic write after the parent constructor' => ['RefilledProblem', ['type' => true, 'title' => true, 'status' => true, 'detail' => null]],
    // A member assigned on every path and then unset on one is left out on that one; the analyser tracks no unset.
    'an unset after the parent constructor' => ['UntitledNotice', ['title' => false, 'detail' => true]],
    'an unset after its own assignment' => ['RetractedNotice', ['title' => true, 'detail' => false]],
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

it('publishes a problem built through its parent\'s constructor as the same constructor written out would be', function (): void {
    // Presence is read off the class by reflection too, so it is loaded here: the same source, not a twin.
    loadFixtureAppClasses('Problems');
    $classes = ['FlatProblem', 'InstanceProblem', 'ApiProblem', 'TracedProblem', 'ExplainedProblem', 'DeferredProblem'];
    $metadata = [];
    foreach ($classes as $class) {
        $metadata['App\\Problems\\'.$class] = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Problems\\'.$class));
    }

    $registry = new ComponentRegistry;
    $converter = new SchemaConverter(DefaultTypeMappers::all(), new StubTypeEngine(classes: $metadata), $registry);
    foreach (array_keys($metadata) as $fqcn) {
        $converter->toSchema(new ClassT($fqcn));
    }
    $schemas = $registry->schemas();
    ksort($schemas);

    // A bare 404 sends `{"type":"about:blank","title":"Not Found","status":404,"instance":"/…"}`: no
    // `detail`, and a `type` no instance holds otherwise. The class running its parent's constructor is
    // the class with that constructor written into it, but for the order reflection lists members in.
    $unordered = static function (array $schema): array {
        ksort($schema['properties']);
        sort($schema['required']);

        return $schema;
    };

    expect($unordered($schemas['InstanceProblem']))->toBe($unordered($schemas['FlatProblem']))
        ->and($schemas['InstanceProblem']['required'])->toBe(['instance', 'type', 'title', 'status'])
        ->and($schemas['InstanceProblem']['properties']['type'])->toBe(['type' => 'string', 'const' => 'about:blank'])
        // An open class may be built by a subclass's constructor, so it pins nothing — and still says what
        // its own constructor leaves out.
        ->and($schemas['ApiProblem']['properties']['type'])->toBe(['type' => 'string'])
        ->and($schemas['ApiProblem']['required'])->not->toContain('detail')
        ->and($schemas['TracedProblem']['required'])->not->toContain('detail')
        ->and($schemas['TracedProblem']['properties']['type'])->toBe(['type' => 'string', 'const' => 'about:blank'])
        ->and($schemas['ExplainedProblem']['required'])->toContain('detail')
        // Unproved, so published as before: every member required.
        ->and($schemas['DeferredProblem']['required'])->toContain('detail');

    assertGoldenAt(dirname(__DIR__).'/Fixtures/golden/problem-hierarchy.components.json', json_encode($schemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
})->group('fixture');
