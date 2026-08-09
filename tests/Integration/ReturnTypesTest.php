<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Ports Spike A's return-path pass criteria: per-return flow-refined types, the
 * JsonResponse payload-shape stub, resource collections, and a distinct type per
 * return in a union action. Assertions run over the serialized ActionAnalysis
 * the engine subprocess emits.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return list<array<string, mixed>>
 */
function spikeReturns(string $method): array
{
    $analysis = FixtureRunner::analyze(
        'app/Http/Controllers/SpikeController.php',
        'App\\Http\\Controllers\\SpikeController',
        $method,
    );

    /** @var list<array<string, mixed>> $returns */
    $returns = $analysis['returns'];

    return $returns;
}

it('recovers an Eloquent Collection generic for listUsers', function (): void {
    $returns = spikeReturns('listUsers');

    expect($returns)->toHaveCount(1);
    $type = $returns[0]['type'];
    expect($type['kind'])->toBe('class')
        ->and($type['fqcn'])->toContain('Collection')
        ->and($type['typeArgs'])->not->toBeEmpty();
    $last = $type['typeArgs'][count($type['typeArgs']) - 1];
    expect($last['kind'])->toBe('class')
        ->and($last['fqcn'])->toBe('App\\Models\\User');
})->group('fixture');

it('recovers the JsonResponse payload shape and folded status via the bundled stub', function (): void {
    $returns = spikeReturns('jsonShape');

    expect($returns)->toHaveCount(1);
    $type = $returns[0]['type'];
    // JsonResponse<arrayShape{...}, 200>: the payload shape plus the default folded status literal.
    expect($type['kind'])->toBe('class')
        ->and($type['fqcn'])->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type['typeArgs'])->toHaveCount(2)
        ->and($type['typeArgs'][0]['kind'])->toBe('arrayShape')
        ->and($type['typeArgs'][1]['kind'])->toBe('literal')
        ->and($type['typeArgs'][1]['value'])->toBe(200);
})->group('fixture');

it('recovers an AnonymousResourceCollection for resourceCollection', function (): void {
    $returns = spikeReturns('resourceCollection');

    expect($returns)->toHaveCount(1);
    expect($returns[0]['type']['fqcn'])->toContain('AnonymousResourceCollection');
})->group('fixture');

it('recovers response()->noContent() as JsonResponse<void, 204> on the real engine', function (): void {
    // The noContent() branch of the bundled ResponseJsonReturnTypeExtension had no surviving real
    // proof (its spike evidence was deleted); this pins it end-to-end: a void payload + folded 204.
    $returns = spikeReturns('noContent');

    expect($returns)->toHaveCount(1);
    $type = $returns[0]['type'];
    expect($type['kind'])->toBe('class')
        ->and($type['fqcn'])->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type['typeArgs'])->toHaveCount(2)
        ->and($type['typeArgs'][0]['kind'])->toBe('void')
        ->and($type['typeArgs'][1]['kind'])->toBe('literal')
        ->and($type['typeArgs'][1]['value'])->toBe(204);
})->group('fixture');

it('recovers a spatie two-arg paginated collection generic with the item as the LAST arg', function (): void {
    // Real-engine/docblock proof for the A1 fix: spatie's collectables are `@template TKey of
    // array-key, @template TValue`, so `PaginatedDataCollection<int, ArticleData>` recovers as a
    // TWO-arg generic whose ITEM is the last arg. Reading typeArgs[0] would type the items as the
    // integer key — the confirmed live bug the SpatieData mapper (DataClassReflector::collectionValueType)
    // now reads correctly.
    $analysis = FixtureRunner::analyze(
        'app/Http/Controllers/PaginatedCollectionController.php',
        'App\\Http\\Controllers\\PaginatedCollectionController',
        'index',
    );
    /** @var list<array<string, mixed>> $returns */
    $returns = $analysis['returns'];

    expect($returns)->toHaveCount(1);
    $type = $returns[0]['type'];
    expect($type['kind'])->toBe('class')
        ->and($type['fqcn'])->toBe('Spatie\\LaravelData\\PaginatedDataCollection')
        ->and($type['typeArgs'])->toHaveCount(2)
        ->and($type['typeArgs'][0]['kind'])->toBe('scalar')
        ->and($type['typeArgs'][0]['scalar'])->toBe('int');

    $item = $type['typeArgs'][count($type['typeArgs']) - 1];
    expect($item['kind'])->toBe('class')
        ->and($item['fqcn'])->toBe('App\\Data\\ArticleData');
})->group('fixture');

it('reports a distinct type per return path in a union action', function (): void {
    $returns = spikeReturns('unionAction');

    expect($returns)->toHaveCount(2);
    $fqcns = array_map(static fn (array $r): string => $r['type']['fqcn'] ?? '?', $returns);
    expect($fqcns)->toContain('Illuminate\\Http\\JsonResponse')
        ->and($fqcns)->toContain('App\\Http\\Resources\\UserResource');

    expect($returns[0]['location']['line'] ?? null)->not->toBe($returns[1]['location']['line'] ?? null);
})->group('fixture');
