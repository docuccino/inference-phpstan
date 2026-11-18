<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

/**
 * The union assertion in `UnplacedStatusReconciliationTest` given teeth off the engine: a classification
 * outside the five named columns has to be CAUGHT, and a partly-demoted action has to be one of them
 * rather than quietly counted as placed.
 *
 * Counting could tell neither story. `array_sum(array_count_values($x)) === count($x)` is arithmetic —
 * true of every input, and still true once a fifth column appears — so a guard written that way reads
 * like a partition check and asserts nothing. This is what makes the one over the sweep an assertion.
 */
$row = static fn (int $surfaced, int $demoted, array $unplaced = [], int $asserted = 0): array => [
    'unplaced' => $unplaced,
    'demotedThrows' => $demoted,
    'assertedThrows' => $asserted,
    'surfaced' => $surfaced,
];

it('classifies each swept action, and gives a partly-demoted one a column of its own', function () use ($row): void {
    expect(unplacedColumn($row(0, 0)))->toBe('unsurfaced')
        ->and(unplacedColumn($row(2, 0, ['App\\X' => ['X.php:3']])))->toBe('unplaced')
        ->and(unplacedColumn($row(2, 2)))->toBe('demoted')
        // Some throws demoted and some not: no ledger row describes that, so it must not land in
        // `placed`, where an action publishing fewer responses than it raises would go unremarked.
        ->and(unplacedColumn($row(2, 1)))->toBe('mixed')
        ->and(unplacedColumn($row(2, 0)))->toBe('placed')
        // Every throw an assertion: the document carries none of them, which its own ledger accounts for.
        ->and(unplacedColumn($row(2, 0, [], 2)))->toBe('asserted')
        // An assertion beside a placed error: the error is published, and the assertion says why it is not.
        ->and(unplacedColumn($row(2, 0, [], 1)))->toBe('placed')
        // A demotion alongside is still the unexplained case, whatever the rest are.
        ->and(unplacedColumn($row(2, 1, [], 1)))->toBe('mixed');

    // Two demoted throws of ONE class is all-demoted, not partly: `demoted` dedupes by FQCN, so holding
    // that set against the throw count read this action as half placed.
    expect(unplacedColumn($row(2, 2)))->toBe('demoted');
});

it('rejects a column the five guards do not cover', function (): void {
    $columns = ['A::a' => 'placed', 'B::b' => 'mixed'];

    expect(array_keys(array_diff($columns, unplacedColumnNames())))->toBe(['B::b'])
        // The identity the guard used to rest on, stated so it is plainly not a check.
        ->and(array_sum(array_count_values($columns)))->toBe(count($columns));
});
