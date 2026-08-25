<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Where an authored `@example` is REACHABLE at all, against the real analyser. The tag was read on the
 * Data-class path and written off by three other producers, and wiring those three raised the prior
 * question this pins the answer to: which of their shapes can carry the tag in the first place.
 *
 * A native property carries a docblock of its own, so a plain DTO's `@example` is recovered. A magic
 * `@property` tag has no docblock to put one in, so an idiomatic model — the shape a real app writes,
 * and the shape `App\Models\Listing` deliberately is — recovers none, for the docblock exactly as for
 * `#[Example]`. That is a limit of the shape, not of the reader, and it is pinned here so nobody reads
 * the wiring as a promise the shape cannot keep.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** Property name → the raw `@example` text the engine recovered, for a fixture-app class. */
function recoveredExamples(string $fqcn): array
{
    $examples = [];
    foreach (ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn))->properties as $property) {
        $examples[$property->name] = $property->example;
    }

    return $examples;
}

it('recovers an @example off a plain DTO property, which is not a Data class', function (string $property, string $text): void {
    // The engine had always recovered these; the generic class mapper simply never asked. Text is all a
    // tag can hold, so the reading against the declared type happens downstream, in TypedExample.
    expect(recoveredExamples('App\\Support\\RetentionPolicy')[$property])->toBe($text);
})->with([
    'a string' => ['plan', 'enterprise'],
    'an integer' => ['days', '90'],
    'a boolean' => ['irreversible', 'true'],
    'a JSON array literal' => ['regions', '["eu-west-1", "us-east-1"]'],
    // Recovered as written; it is the reading that refuses it, with a diagnostic naming the property.
    'a literal with no reading' => ['grace_days', 'n/a'],
])->group('fixture');

it('keeps the prose and the example of the same plain DTO property together', function (): void {
    // Both come off the one docComment, so a producer reading one has already been handed the other.
    $properties = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Support\\RetentionPolicy'))->properties;

    $plan = null;
    foreach ($properties as $property) {
        if ($property->name === 'plan') {
            $plan = $property;
        }
    }

    expect($plan?->summary)->toBe('The plan this policy belongs to.')
        ->and($plan?->example)->toBe('enterprise');
})->group('fixture');

it('recovers no example for a magic @property column, because there is no docblock to write one in', function (): void {
    // The idiomatic Eloquent shape: every column is a class-level `@property` tag. The tag carries a
    // description — which IS recovered, and becomes the column's `description` — and has no room for an
    // example. Wiring the model mapper to the docblock reader therefore publishes nothing here, and
    // pretending otherwise would need a tag syntax no ide-helper convention has.
    $examples = recoveredExamples('App\\Models\\Listing');

    // A scan that matched nothing would pass forever, so assert the columns really are here first.
    expect(array_keys($examples))->toContain('id', 'title', 'status', 'active')
        ->and(array_filter($examples, static fn (?string $e): bool => $e !== null))->toBe([]);
})->group('fixture');
