<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * `inference.descend-scope-narrowed`: where it fires, where it stays silent, and what widening the scope
 * actually buys.
 *
 * The notice exists for one population — an application that wrote `engine.project_paths` itself and so
 * descends less far than the roots it declares. Everything that ran `docuccino:install` before the shipped
 * template stopped writing the key is in it, and commenting a template out does not edit anybody's file,
 * so the build has to say so. The default descends into every shipped root, which is why the first
 * assertion here is a silence: a notice that fired on an ordinary build would be a channel nobody reads.
 * That silence is held by the recorder's own gate rather than by this corpus — `SkippedDescents` keeps
 * only a hop the DECLARED scope would have opened, which `SkippedDescentsTest` proves directly, since a
 * corpus with no `autoload-dev` callee cannot tell a real gate from a lucky one.
 *
 * Both scopes are the same corpus through the same engine — the difference is `descendPaths` and nothing
 * else ({@see FixtureRunner::analyzeManyNarrow()}) — so the delta between them is the measurement the
 * default was changed on, executed rather than quoted.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('says nothing at the scope the application declares', function (): void {
    foreach (throwCorpusControllers() as $relPath => $class) {
        $analyses = FixtureRunner::analyzeMany($relPath, $class, throwActionMethods($relPath));

        $codes = [];
        foreach ($analyses as $analysis) {
            /** @var array{diagnostics: list<array{code?: string}>} $analysis */
            foreach ($analysis['diagnostics'] as $diagnostic) {
                $codes[] = (string) ($diagnostic['code'] ?? '');
            }
        }

        // The corpus really reported things, so this is a silence about ONE code rather than an analysis
        // that said nothing at all — which would pass this assertion forever.
        expect($codes)->not->toBeEmpty()
            ->and($codes)->not->toContain('inference.descend-scope-narrowed');
    }
})->group('fixture');

it('names every call a pinned scope kept it out of, and nothing the reader cannot act on', function (): void {
    // Each notice as (action, the file descent stopped at). The file is read back out of the sentence
    // because it is the one thing the reader has to act on, so a message that stopped naming it fails.
    $notices = static function (string $relPath, string $class): array {
        $out = [];
        foreach (FixtureRunner::analyzeManyNarrow($relPath, $class, throwActionMethods($relPath)) as $method => $analysis) {
            /** @var array{diagnostics: list<array{code?: string, message?: string, help?: string}>} $analysis */
            foreach ($analysis['diagnostics'] as $diagnostic) {
                if (($diagnostic['code'] ?? null) !== 'inference.descend-scope-narrowed') {
                    continue;
                }

                $message = (string) ($diagnostic['message'] ?? '');
                expect((string) ($diagnostic['help'] ?? ''))->toContain('engine.project_paths')
                    ->and(preg_match('/: ([^ ]+\.php) is outside the directories inference descends into/', $message, $found))->toBe(1);

                $out[] = [$class.'::'.$method, $found[1]];
            }
        }

        return $out;
    };

    $hits = [];
    foreach (throwCorpusControllers() as $relPath => $class) {
        $hits = [...$hits, ...$notices($relPath, $class)];
    }

    // The two counts the diagnostic was sized on. Actionable is not asserted as a property of the
    // notice's own opinion: every file it names has to be one the application declares under `autoload`,
    // which is exactly the set removing the key descends into — so the remedy the help gives really does
    // reach it. Anything else lands in the second count, which must stay empty.
    $actionable = [];
    $unactionable = [];
    foreach ($hits as [$action, $file]) {
        expect(FixtureRunner::path($file))->toBeFile();

        fixtureDescends($file)
            ? $actionable[] = $action.' -> '.$file
            : $unactionable[] = $action.' -> '.$file;
    }

    expect($hits)->not->toBeEmpty()
        ->and($unactionable)->toBe([])
        ->and(count($actionable))->toBe(count($hits));

    // A modular callee reached from `app/`, and another reached from a second modular root: the two
    // shapes the narrowing loses, so a predicate that stopped seeing one of them fails here.
    $files = array_map(static fn (array $hit): string => $hit[1], $hits);
    expect($files)->toContain('modules/Billing/LedgerReviewQuery.php');

    $callers = array_unique(array_map(
        static fn (array $hit): string => explode('::', $hit[0], 2)[0],
        $hits,
    ));
    expect($callers)->toContain('App\\Http\\Controllers\\ThrowsController')
        ->and($callers)->toContain('Modules\\Billing\\LedgerThrowsController');
})->group('fixture');

it('surfaces at the declared scope exactly the throws a pinned scope loses', function (): void {
    $surfaced = static function (string $relPath, string $class, bool $narrow): array {
        $analyses = $narrow
            ? FixtureRunner::analyzeManyNarrow($relPath, $class, throwActionMethods($relPath))
            : FixtureRunner::analyzeMany($relPath, $class, throwActionMethods($relPath));

        $out = [];
        foreach ($analyses as $method => $analysis) {
            /** @var array{throws: list<array{disposition?: string, exceptionFqcn?: string, httpStatusHint?: int|null}>} $analysis */
            foreach ($analysis['throws'] as $throw) {
                if (($throw['disposition'] ?? null) !== 'signal') {
                    continue;
                }

                $out[] = $class.'::'.$method.' '.(string) ($throw['exceptionFqcn'] ?? '')
                    .' @'.(string) ($throw['httpStatusHint'] ?? 'none');
            }
        }

        return $out;
    };

    $narrow = [];
    $wide = [];
    foreach (throwCorpusControllers() as $relPath => $class) {
        $narrow = [...$narrow, ...$surfaced($relPath, $class, true)];
        $wide = [...$wide, ...$surfaced($relPath, $class, false)];
    }

    // Widening only ever adds: a response the narrow scope published and the wide one does not would be
    // a regression the counts alone would hide.
    expect(array_values(array_diff($narrow, $wide)))->toBe([]);

    // And it really adds — the two modular throws the unsurfaced ledger used to excuse, each with the
    // status the exception class pins rather than a placeholder 500. Written out because the point of the
    // change is these two responses existing, and a count would pass on any two.
    expect(array_values(array_diff($wide, $narrow)))->toBe([
        'App\\Http\\Controllers\\ThrowsController::modularThrowSiteStatus App\\Exceptions\\ManifestRejectedException @404',
        'Modules\\Billing\\LedgerThrowsController::modularExceptionOneCallAway App\\Exceptions\\ManifestRejectedException @404',
    ]);
})->group('fixture');
