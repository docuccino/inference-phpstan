<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A cold build's workers are forked copies of the one engine the build booted, and each asks the units it
 * claims in the order it claims them — which is timing, not canonical route order. That the document is the
 * serial one rests on every answer being the same whoever asked first, so here the real engine analyses and
 * traces every action of controllers that share models, resources, traits and throwing callees, three ways
 * in forks of one boot: all in order, all backwards, and each controller alone, as a worker that only ever
 * claimed that unit asks it.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('answers every action the same in a forked worker, whatever that worker asked before it', function (): void {
    $answered = FixtureRunner::analyzeForked(
        'App\\Http\\Controllers\\ResourceResponseController',
        'App\\Http\\Controllers\\KeyedCollectionController',
        'App\\Http\\Controllers\\ListedCollectionController',
        'App\\Http\\Controllers\\PaginatedCollectionController',
        'App\\Http\\Controllers\\StatusChoiceController',
        'App\\Http\\Controllers\\ThrowsController',
        'App\\Http\\Controllers\\ProblemController',
        'App\\Http\\Controllers\\UserListController',
        'App\\Http\\Controllers\\UserPageController',
        'App\\Http\\Controllers\\UserWriteController',
        'App\\Http\\Controllers\\ListingQueryController',
        'App\\Http\\Controllers\\PositionQueryController',
        'App\\Http\\Controllers\\ShipmentQueryController',
        'App\\Http\\Controllers\\SpikeController',
    );

    $forward = $answered['forward'];
    $recovered = static fn (string $part, int|string $member): int => count(array_filter(
        $forward,
        static fn (array $answer): bool => $answer[$part][$member] !== [],
    ));

    // Every fork answered every action, and the answers have substance: an engine that failed everywhere
    // alike, or a sweep that found no actions, would leave the three ways equal all the same.
    expect(count($forward))->toBeGreaterThan(100)
        ->and($recovered('analysis', 'returns'))->toBeGreaterThan(40)
        ->and($recovered('analysis', 'throws'))->toBeGreaterThan(40)
        ->and($recovered('trace', 0))->toBeGreaterThan(2)
        ->and(array_keys($answered['reversed']))->toBe(array_keys($forward))
        ->and(array_keys($answered['alone']))->toBe(array_keys($forward));

    foreach ($forward as $action => $answer) {
        expect($answered['reversed'][$action])->toBe($answer, $action.' answered differently asked last')
            ->and($answered['alone'][$action])->toBe($answer, $action.' answered differently asked without the other controllers');
    }
})->group('fixture');
