<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The two conditions held against each other, in BOTH directions: what the document PUBLISHES under a
 * status nothing stated, and what the build REPORTS about it.
 *
 * A signalled throw with no status hint is one the adapter has to key by CLASSIFICATION rather than by
 * a reading — `FrameworkExceptionTable`'s answer for the class where it has one, and
 * `UNPLACED_STATUS` otherwise, a 500 that stands in for a status nobody read. Where the build says
 * nothing about one, the reader is left with a response they cannot explain and no line to go and look
 * at. The other way round is a defect too, and a worse-sounding one: a notice asking an author to pin a
 * status for a response the document does not carry sends them to a line that changes nothing.
 *
 * The ledger is what makes this a guard rather than a mirror. It is stated from the CONTRACT — a
 * notice may only address somebody who can act, so silence is owed exactly where the fold gave up on
 * code the reader does not own — and never derived from what the analyser answers, so a new silent
 * row fails this test until a human writes down why nobody could have acted on it. And the reason each
 * row gives is CHECKED rather than trusted: the contract's own words make it a question about the
 * declaration the fold read, so the entry has to be a class this application does not declare.
 *
 * Both directions are keyed on (action, class), which is the unit the DOCUMENT has: `ThrowAnalyzer`
 * dedupes its result on `(fqcn, httpStatusHint)`, so an action throwing one class at two lines with
 * neither status folding publishes ONE unplaced response, and there is no second published fact for a
 * per-site comparison to hold a notice against. Keying the reverse direction by site would therefore
 * fail on a shape that is ordinary and correct — two lines, two notices, one deduped response, one of
 * them read as reporting something the document does not carry. The premise is asserted below rather
 * than trusted, so if the result ever stops collapsing those the argument expires with a failure
 * instead of quietly covering less.
 *
 * The ledger's unit is the class for the same reason, and its excuse survives the coarser key: what it
 * proves of an entry — the declarations that would have stated a status are somebody else's — is a
 * property of the CLASS, so every throw site that reaches one inherits the same proof. A silent class
 * that is not written down fails, whichever line it is written at.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * The silent throws, by exception class, with the reason no notice is owed. The one entry is Symfony's
 * own: it forwards no status slot a construction could fill, and the number it pins is written in a
 * `vendor/` constructor whose body PHPStan strips, so nothing the reader could edit would have made it
 * readable. The document does not lie about it — the framework table classifies the class at the 409 it
 * pins — but that is the adapter's knowledge, not a reading, so the analyser's silence is what this
 * ledger is about.
 *
 * @return array<string, string>
 */
function unactionableUnplacedThrows(): array
{
    return [
        'Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException' => 'declared in vendor/, so the status it sets was never read and the edit that would state it is not the reader’s to make',
    ];
}

/**
 * Whether the fixture application declares a class, read off its own autoload map rather than listed
 * here — the same question the analyser's project filter asks, answered from the source of truth so
 * this states the rule independently of what the engine happens to do with it.
 *
 * Every section of the map is read, not just `psr-4`: an application autoloads its own classes by
 * `classmap` and `files` too, and a reader that knew only one section would answer "not the
 * application's" for a class it declares — which is the one answer that lets a silence be excused
 * wrongly. The dev sections count as well; a class the application declares is its author's to edit
 * whichever half of the map names it.
 */
function fixtureDeclaresClass(string $fqcn): bool
{
    /** @var array<string, array{psr-4?: array<string, string>, classmap?: list<string>, files?: list<string>}> $composer */
    $composer = json_decode((string) file_get_contents(FixtureRunner::path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $roots = [];
    $paths = [];
    foreach (['autoload', 'autoload-dev'] as $section) {
        $roots = [...$roots, ...array_map(
            static fn (string $prefix, string $directory): array => [$prefix, $directory],
            array_keys($composer[$section]['psr-4'] ?? []),
            array_values($composer[$section]['psr-4'] ?? []),
        )];
        $paths = [...$paths, ...($composer[$section]['classmap'] ?? []), ...($composer[$section]['files'] ?? [])];
    }

    // A map that stopped parsing would call every class foreign and pass this file forever.
    expect($roots)->not->toBeEmpty();

    foreach ($roots as [$prefix, $directory]) {
        if ($prefix === '' || ! str_starts_with($fqcn, $prefix)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix)));
        if (is_file(FixtureRunner::path(rtrim($directory, '/').'/'.$relative.'.php'))) {
            return true;
        }
    }

    // The sections that name no namespace at all: the declaration has to be looked for.
    $namespace = str_contains($fqcn, '\\') ? substr($fqcn, 0, (int) strrpos($fqcn, '\\')) : '';
    $short = str_contains($fqcn, '\\') ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1) : $fqcn;
    $declares = '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+'.preg_quote($short, '/').'\b/m';

    foreach (fixtureAutoloadedFiles($paths) as $file) {
        $source = (string) file_get_contents($file);
        $declared = preg_match('/^\s*namespace\s+([^;{]+)/m', $source, $matches) === 1 ? trim($matches[1]) : '';
        if ($declared === $namespace && preg_match($declares, $source) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * The PHP files a list of `classmap`/`files` entries covers — an entry is a file or a directory.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function fixtureAutoloadedFiles(array $paths): array
{
    $files = [];
    foreach ($paths as $entry) {
        $path = FixtureRunner::path(rtrim($entry, '/'));
        if (is_file($path)) {
            $files[] = $path;

            continue;
        }

        if (! is_dir($path)) {
            continue;
        }

        /** @var SplFileInfo $found */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $found) {
            if ($found->isFile() && $found->getExtension() === 'php') {
                $files[] = $found->getPathname();
            }
        }
    }

    return $files;
}

/**
 * Every action the fixture controller declares, read off the file rather than listed here: a new
 * action whose unplaced status goes unreported has to fail this guard, and it cannot if the guard
 * only knows the actions somebody remembered to add.
 *
 * @return list<string>
 */
function throwActionMethods(): array
{
    $source = (string) file_get_contents(FixtureRunner::path('app/Http/Controllers/ThrowsController.php'));

    preg_match_all('/^    public function (\w+)\(/m', $source, $matches);

    /** @var list<string> $methods */
    $methods = $matches[1];

    // A scan that matched nothing must fail rather than pass forever — the file is real and holds
    // dozens of actions, so a pattern that stopped seeing them is the defect, not an empty corpus.
    expect(count($methods))->toBeGreaterThan(30);

    return $methods;
}

/**
 * One sweep of the whole controller, decoded into the two sides this file compares: per action, the
 * classes a notice named, and the throws the document publishes with no status of their own.
 *
 * @return array<string, array{named: array<string, true>, unplaced: array<string, list<string>>}>
 */
function unplacedSweep(): array
{
    // Both tests below read the same sweep, and it is a real-engine analysis of the whole controller in
    // a subprocess — so it is taken once per process rather than once per test.
    /** @var array<string, array{named: array<string, true>, unplaced: array<string, list<string>>}>|null $memo */
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $analyses = FixtureRunner::analyzeMany(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        throwActionMethods(),
    );

    $sweep = [];
    foreach ($analyses as $method => $analysis) {
        /** @var array{throws: list<array<string, mixed>>, diagnostics: list<array<string, mixed>>} $analysis */
        $named = [];
        foreach ($analysis['diagnostics'] as $diagnostic) {
            if (($diagnostic['code'] ?? null) !== 'inference.http-exception-status-unread') {
                continue;
            }

            // The sentence opens with the class it is about — the same shape the reader sees.
            $message = (string) $diagnostic['message'];
            $named[substr($message, 0, (int) strpos($message, ' '))] = true;
        }

        $unplaced = [];
        foreach ($analysis['throws'] as $throw) {
            // Only a SIGNAL reaches the document, and the invariant is about what the document
            // publishes: an `internal` throw is carried nowhere, so there is no response for a reader
            // to be unable to explain. One that stops being demoted arrives here as a new row.
            if ($throw['httpStatusHint'] !== null || ($throw['disposition'] ?? null) !== 'signal') {
                continue;
            }

            /** @var list<array{symbol: string, location: array{file: string, line: int}}> $chain */
            $chain = $throw['callChain'];
            $deepest = $chain[count($chain) - 1]['location'];

            $unplaced[(string) $throw['exceptionFqcn']][] = basename($deepest['file']).':'.$deepest['line'];
        }

        $sweep[(string) $method] = ['named' => $named, 'unplaced' => $unplaced];
    }

    return $memo = $sweep;
}

it('reports every unplaced status it publishes, bar the ones nobody can act on', function (): void {
    $unreported = [];
    $reported = 0;
    $unplaced = 0;

    foreach (unplacedSweep() as $method => $sides) {
        foreach ($sides['unplaced'] as $fqcn => $sites) {
            // The premise the (action, class) key rests on, executed rather than argued: a result
            // deduped on `(fqcn, httpStatusHint)` cannot carry two unplaced responses for one class, so
            // there is no second site for a notice to be held against. If that ever stops holding, the
            // granularity question is reopened here rather than silently answered by this file.
            expect($sites)->toHaveCount(1);

            $unplaced += count($sites);

            if (isset($sides['named'][$fqcn])) {
                $reported += count($sites);

                continue;
            }

            foreach ($sites as $site) {
                $unreported[$fqcn][] = $method.' at '.$site;
            }
        }
    }

    ksort($unreported);
    $ledger = unactionableUnplacedThrows();
    ksort($ledger);

    // The corpus really contains both halves. Without this the comparison below is satisfied by an
    // analysis that surfaced no throws at all.
    expect($unplaced)->toBeGreaterThan(8)
        ->and($reported)->toBeGreaterThan(6);

    // Exactly the ledger: nothing silent that is not written down, and nothing written down that the
    // build has since started reporting — a stale excuse is how a notice ends up owed and never given.
    expect(array_keys($unreported))->toBe(array_keys($ledger));
})->group('fixture');

/**
 * The ledger checked rather than believed. Its rows are prose a future author could satisfy by pasting
 * any sentence, but the contract behind them is not: a notice is owed wherever the fold read code the
 * reader owns, so an entry is only excusable while the declarations that would have stated the status
 * are outside the application. Written down as an assertion, the excuse expires by itself.
 */
it('excuses a silence only for a class the application does not declare', function (): void {
    $ledger = unactionableUnplacedThrows();

    expect($ledger)->not->toBeEmpty();

    foreach ($ledger as $fqcn => $reason) {
        expect(fixtureDeclaresClass($fqcn))->toBeFalse()
            ->and($reason)->not->toBe('');
    }

    // The scanner answering "no" to everything would pass the loop above without proving anything, so
    // it is held against a class the fixture certainly does declare.
    expect(fixtureDeclaresClass('App\\Exceptions\\ExportConflictException'))->toBeTrue();
})->group('fixture');

/**
 * The other direction, which is reachable and worse to read: `ThrowSignal` demotes a declared throw
 * reached through a callee it could not place, so the document carries no response for it — while the
 * status read still records a notice for any project class whose number did not fold. The reader is
 * then told to go and pin a status for a response nothing publishes.
 */
it('reports nothing about a throw the document does not publish', function (): void {
    $stray = [];
    $checked = 0;

    foreach (unplacedSweep() as $method => $sides) {
        foreach (array_keys($sides['named']) as $fqcn) {
            $checked++;
            if (! isset($sides['unplaced'][$fqcn])) {
                $stray[] = $method.' reports '.$fqcn;
            }
        }
    }

    // Again the anti-vacuity floor: the sweep really has notices to hold against the document.
    expect($checked)->toBeGreaterThan(6)
        ->and($stray)->toBe([]);
})->group('fixture');
