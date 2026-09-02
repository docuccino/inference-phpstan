<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A class's recovered shape is written across as many files as its declaration spans, and the fragment
 * cache is only sound if it knows about all of them. Named the subject's own file and nothing else,
 * `dependencyFiles` leaves every fragment built on it warm and wrong when the parent that declares half
 * its properties is edited — or the trait, or the enum whose cases are copied into it.
 *
 * Real reflection, in the provisioned app: a stub cannot answer which file a property was declared in.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The recovered metadata for one of the fixture app's Data classes. */
function metadataFor(string $fqcn): ClassMetadata
{
    return ClassMetadata::fromArray(FixtureRunner::classMetadata($fqcn));
}

it('names every file an inherited shape was written across', function (): void {
    $metadata = metadataFor('App\\Data\\ListingSummaryData');
    $files = $metadata->dependencyFiles;

    expect($files)->toContain(FixtureRunner::path('app/Data/ListingSummaryData.php'))
        // The parent declares `id` and `status`…
        ->toContain(FixtureRunner::path('app/Data/BaseListingData.php'))
        // …the trait declares `revision`, and PHP reports it as the PARENT's, so only the trait walk
        // finds this file…
        ->toContain(FixtureRunner::path('app/Data/Concerns/HasRevision.php'))
        // …and the enum's CASES are copied into the metadata, so adding one changes this answer.
        ->toContain(FixtureRunner::path('app/Enums/ListingStatus.php'))
        // The properties really do come from all three, so the row is about a shape, not a file list.
        ->and(array_map(static fn ($p): string => $p->name, $metadata->properties))
        ->toEqualCanonicalizing(['id', 'status', 'title', 'revision']);
})->group('fixture');

it('points an inherited property at the file that declares it', function (): void {
    // Provenance, not caching: a `SourceLocation` in the child's file sends a reader to a line that
    // says something else entirely.
    $byName = [];
    foreach (metadataFor('App\\Data\\ListingSummaryData')->properties as $property) {
        $byName[$property->name] = $property->location?->file;
    }

    expect($byName['title'])->toBe(FixtureRunner::path('app/Data/ListingSummaryData.php'))
        ->and($byName['id'])->toBe(FixtureRunner::path('app/Data/BaseListingData.php'))
        ->and($byName['status'])->toBe(FixtureRunner::path('app/Data/BaseListingData.php'))
        ->and($byName['revision'])->toBe(FixtureRunner::path('app/Data/BaseListingData.php'));
})->group('fixture');

it('names only its own declaration for a class that inherits nothing from the app', function (): void {
    // The other half: the list is a function of what the class is made of, not a blanket. A Data class
    // with no app-level parent, trait or enum names none of the three files above.
    $files = metadataFor('App\\Data\\ProblemDocumentData')->dependencyFiles;

    expect($files)->toContain(FixtureRunner::path('app/Data/ProblemDocumentData.php'))
        ->not->toContain(FixtureRunner::path('app/Data/BaseListingData.php'))
        ->not->toContain(FixtureRunner::path('app/Data/Concerns/HasRevision.php'))
        ->not->toContain(FixtureRunner::path('app/Enums/ListingStatus.php'));
})->group('fixture');

it('invalidates a cached fragment when the file the shape was inherited from is edited', function (string $edited): void {
    // The end of the chain, through the real cache: the recovered dependency list is what a fragment
    // stores, and editing any file on it has to make the entry stale.
    $dependencies = metadataFor('App\\Data\\ListingSummaryData')->dependencyFiles;

    expect(fragmentAcrossDependencyEdit($dependencies, $edited))->toBe(['warm' => true, 'staleAfterEdit' => true]);
})->with([
    'the parent class' => ['app/Data/BaseListingData.php'],
    'the trait' => ['app/Data/Concerns/HasRevision.php'],
    'the enum whose cases it copied' => ['app/Enums/ListingStatus.php'],
])->group('fixture');
