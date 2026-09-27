<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/*
 * The real engine's half of a tagged union of plain classes, in an installed app: the class that fixes
 * its tag in its constructor has the tag typed as that one wire value, and an action answering with the
 * sealed interface or a union of its members hands the schema chain those types — which is what the
 * chain turns into a discriminated `oneOf`.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('types a tag the constructor fixes as its backing value', function (string $class, string $value): void {
    $metadata = ClassMetadata::fromArray(FixtureRunner::classMetadata($class));

    $types = [];
    foreach ($metadata->properties as $property) {
        $types[$property->name] = $property->type;
    }

    expect($types['type'])->toEqual(new LiteralT($value));
})->with([
    'a comment' => ['App\\Timeline\\CommentEntry', 'comment'],
    'a status change' => ['App\\Timeline\\StatusChangeEntry', 'status_change'],
])->group('fixture');

it('keeps the sealed interface as the element type of a declared list payload', function (): void {
    $analysis = FixtureRunner::analyze('app/Http/Controllers/TimelineController.php', 'App\\Http\\Controllers\\TimelineController', 'index');

    $payload = DType::fromArray($analysis['returns'][0]['type']['typeArgs'][0]);

    // The element is the interface itself, so the sealed tag — not a guess at implementors — decides it.
    expect(json_encode($payload->toArray()))->toContain('"fqcn":"App\\\\Timeline\\\\TimelineEntry"');
})->group('fixture');

it('answers a union of the tagged classes for an action returning either', function (): void {
    $analysis = FixtureRunner::analyze('app/Http/Controllers/TimelineController.php', 'App\\Http\\Controllers\\TimelineController', 'latest');

    $fqcns = [];
    foreach ($analysis['returns'] as $return) {
        $type = $return['type'];
        foreach ($type['kind'] === 'union' ? $type['members'] : [$type] as $member) {
            $fqcns[] = $member['fqcn'] ?? $member['kind'];
        }
    }

    expect(array_values(array_unique($fqcns)))->toEqualCanonicalizing(['App\\Timeline\\CommentEntry', 'App\\Timeline\\StatusChangeEntry']);
})->group('fixture');
