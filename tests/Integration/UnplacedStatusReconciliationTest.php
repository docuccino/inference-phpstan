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
 *
 * And the two directions only ever see a throw the document PUBLISHES with no status, which leaves two
 * populations in the gap between them: a throw the document carries nothing at all for, and one demoted to
 * `internal` and so carried nowhere. Both are worse to read than the placeholder these directions are
 * about — a consumer told nothing cannot even see that an error exists — so each gets a column and a
 * checked ledger of its own, and every swept action is asserted to land in exactly one column. The
 * denominator is asserted too: the actions come off the controllers rather than a list, and BOTH the
 * controller inside the descend scope and the one outside it are swept, because a guard whose corpus is
 * one directory says nothing about the application's other directories.
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

    // The sections that name no namespace at all: the declaration has to be looked for. Off the parsed
    // source, because FALSE is the answer that grants the excuse — a spelling the reader could not
    // recognise ( an attribute on the declaration line, a braced namespace, a name broken over two
    // lines) would keep a ledger row alive that should have expired.
    foreach (fixtureAutoloadedFiles($paths) as $file) {
        if (in_array($fqcn, phpDeclaredTypes((string) file_get_contents($file)), true)) {
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
 * The controllers the sweep covers, by relative path => FQCN. Two, and which two is the point: one inside
 * the descend scope the fixture runs with (`app/`) and one outside it in a primed `Modules\…` root, which
 * is where a modular application writes most of its actions. A corpus of the first alone cannot see
 * anything the second does differently, and every defect this file guards against has a modular spelling.
 *
 * @return array<string, string>
 */
function throwCorpusControllers(): array
{
    return [
        'app/Http/Controllers/ThrowsController.php' => 'App\\Http\\Controllers\\ThrowsController',
        'modules/Billing/LedgerThrowsController.php' => 'Modules\\Billing\\LedgerThrowsController',
    ];
}

/**
 * Every action one controller declares, name → its source, read off the file rather than listed here: a
 * new action whose unplaced status goes unreported has to fail this guard, and it cannot if the guard only
 * knows the actions somebody remembered to add.
 *
 * The two readers below share this ONE grammar. A sweep that recognised a declaration the slicer did not
 * would hand a row an empty body to judge its own excuse against, and the row would pass on nothing; the
 * modifiers are read rather than assumed for the same reason a member is — `final public function` is an
 * action, and a guard blind to the spelling answers "no such action" where the honest answer is a failure.
 *
 * @return array<string, string>
 */
function throwActions(string $relPath): array
{
    $actions = controllerActions((string) file_get_contents(FixtureRunner::path($relPath)));

    // A scan that matched nothing must fail rather than pass forever — both files are real and hold
    // actions, so a pattern that stopped seeing them is the defect, not an empty corpus.
    expect($actions)->not->toBeEmpty();

    return $actions;
}

/**
 * @return list<string>
 */
function throwActionMethods(string $relPath): array
{
    return array_keys(throwActions($relPath));
}

/** The source of one swept action, for a ledger row that has to check its own excuse. */
function throwActionSource(string $relPath, string $method): string
{
    return throwActions($relPath)[$method] ?? '';
}

/**
 * The file the application declares a class in, or null for one it does not declare — the same autoload
 * map {@see fixtureDeclaresClass()} reads, answering where rather than whether.
 */
function fixtureDeclarationFile(string $fqcn): ?string
{
    /** @var array<string, array{psr-4?: array<string, string>}> $composer */
    $composer = json_decode((string) file_get_contents(FixtureRunner::path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach (['autoload', 'autoload-dev'] as $section) {
        foreach ($composer[$section]['psr-4'] ?? [] as $prefix => $directory) {
            if ($prefix === '' || ! str_starts_with($fqcn, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix)));
            $file = rtrim($directory, '/').'/'.$relative.'.php';
            if (is_file(FixtureRunner::path($file))) {
                return $file;
            }
        }
    }

    return null;
}

/**
 * Every class name a snippet of one file names, resolved the way PHP would: an already-qualified name as
 * written, a short name through the file's own `use` map, and anything left over against the file's own
 * namespace. A checker that only understood qualified names would answer "no class here" for the ordinary
 * spelling — a `use` at the top and a bare parameter type — which is the one answer that lets an excuse be
 * believed wrongly.
 *
 * @return list<string>
 */
function fixtureNamesIn(string $relPath, string $snippet): array
{
    $file = (string) file_get_contents(FixtureRunner::path($relPath));

    $namespace = preg_match('/^\s*namespace\s+([^;{]+)/m', $file, $matches) === 1 ? trim($matches[1]) : '';

    $imports = [];
    preg_match_all('/^\s*use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $file, $uses, PREG_SET_ORDER);
    foreach ($uses as $use) {
        $alias = ($use[2] ?? '') !== '' ? $use[2] : substr($use[1], (int) strrpos('\\'.$use[1], '\\'));
        $imports[ltrim($alias, '\\')] = $use[1];
    }

    preg_match_all('/\\\\?([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*)/', $snippet, $found);

    $names = [];
    foreach ($found[1] as $name) {
        $names[$name] = true;

        $head = strstr($name, '\\', true);
        $head = $head === false ? $name : $head;
        if (isset($imports[$head])) {
            $names[$imports[$head].substr($name, strlen($head))] = true;
        }

        if ($namespace !== '' && ! str_contains($name, '\\')) {
            $names[$namespace.'\\'.$name] = true;
        }
    }

    return array_keys($names);
}

/**
 * One sweep of both controllers, decoded into the four columns this file compares. Keyed by
 * `Class::method` — an action's identity now that the corpus spans two classes — and holding, per action:
 * the classes a notice named, the throws the document publishes with no status of their own, the throws it
 * demoted and so carries nowhere, and how many throws the analysis surfaced at all (zero being its own
 * population).
 *
 * @return array<string, array{named: array<string, true>, unplaced: array<string, list<string>>, demoted: array<string, true>, surfaced: int}>
 */
function unplacedSweep(): array
{
    // Every test below reads the same sweep, and it is a real-engine analysis of both controllers in a
    // subprocess — so it is taken once per process rather than once per test.
    /** @var array<string, array{named: array<string, true>, unplaced: array<string, list<string>>, demoted: array<string, true>, demotedThrows: int, surfaced: int}>|null $memo */
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $sweep = [];
    foreach (throwCorpusControllers() as $relPath => $class) {
        $analyses = FixtureRunner::analyzeMany($relPath, $class, throwActionMethods($relPath));

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
            $demoted = [];
            // Throws, not classes: `demoted` dedupes by FQCN, so holding it against `surfaced` would
            // read two demoted throws of one class as one and call an all-demoted action partly placed.
            $demotedThrows = 0;
            foreach ($analysis['throws'] as $throw) {
                // Only a SIGNAL reaches the document. One that does not is carried nowhere, so there is
                // no response for a reader to be unable to explain — and no response at all, which is
                // its own defect and gets its own column rather than being dropped here.
                if (($throw['disposition'] ?? null) !== 'signal') {
                    $demoted[(string) $throw['exceptionFqcn']] = true;
                    $demotedThrows++;

                    continue;
                }

                if ($throw['httpStatusHint'] !== null) {
                    continue;
                }

                /** @var list<array{symbol: string, location: array{file: string, line: int}}> $chain */
                $chain = $throw['callChain'];
                $deepest = $chain[count($chain) - 1]['location'];

                $unplaced[(string) $throw['exceptionFqcn']][] = basename($deepest['file']).':'.$deepest['line'];
            }

            $sweep[$class.'::'.$method] = [
                'named' => $named,
                'unplaced' => $unplaced,
                'demoted' => $demoted,
                'demotedThrows' => $demotedThrows,
                'surfaced' => count($analysis['throws']),
            ];
        }
    }

    return $memo = $sweep;
}

/**
 * The actions the analysis surfaces NO throw for, with the boundary that swallowed it. A row here is the
 * worst outcome of the four: the application really raises an error and the document says nothing, so a
 * consumer cannot even see that the endpoint can fail. Each excuse is checked below, so a new one cannot
 * be closed by pasting a sentence.
 *
 * @return array<string, string>
 */
function unsurfacedThrowActions(): array
{
    return [
        'App\\Http\\Controllers\\ThrowsController::arrowThrownStatus' => 'the `throw` is written in an ARROW function, which PHPStan models with no statement result, so the analysis is handed no throw point to read',
    ];
}

/**
 * The classes the analysis demotes to `internal`, by action, with why the demotion is right. `ThrowSignal`
 * only demotes a DECLARED throw from outside the application, so the excuse each row rests on is that the
 * application does not declare the class — checked, not trusted.
 *
 * @return array<string, string>
 */
function demotedThrowActions(): array
{
    return [
        'App\\Http\\Controllers\\ThrowsController::anyThrowableNoise' => 'Psr\\SimpleCache\\InvalidArgumentException',
        'App\\Http\\Controllers\\ThrowsController::vendorDeclaredHttpStatus' => 'Symfony\\Component\\HttpKernel\\Exception\\UnauthorizedHttpException',
    ];
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
    // Anti-vacuity floors, sized well under what the corpus holds (15 unplaced, 14 of them reported):
    // a floor at "today minus one" fails the first legitimate deletion, which teaches the next author to
    // edit the number rather than read the comparison below it.
    expect($unplaced)->toBeGreaterThan(8)
        ->and($reported)->toBeGreaterThan(8);

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

    // Again the anti-vacuity floor, and again with room under the 14 the corpus holds: the sweep really
    // has notices to hold against the document.
    expect($checked)->toBeGreaterThan(8)
        ->and($stray)->toBe([]);
})->group('fixture');

/**
 * The denominator, asserted rather than assumed. Every guard in this file is a scan over one sweep, and a
 * sweep is silent about a population it never looked at — which is how a modular action went unswept while
 * the whole file stayed green: its status was never read, no notice was owed for it, and both directions
 * agreed about a corpus that did not contain it.
 *
 * So the corpus is held against the axis the defect lived on: which PSR-4 root an action is written in.
 * One controller in `app/` and at least one in a root the application maps separately, both really
 * analysed, both really holding actions. The paths are checked to be what they claim — a row naming a file
 * that has moved would otherwise reduce the sweep to whatever is left.
 */
it('sweeps a controller in a modular root as well as in app/', function (): void {
    $controllers = throwCorpusControllers();
    $sweep = unplacedSweep();

    $inside = [];
    $outside = [];
    foreach ($controllers as $relPath => $class) {
        expect(FixtureRunner::path($relPath))->toBeFile()
            ->and(fixtureDeclarationFile($class))->toBe($relPath);

        $actions = throwActionMethods($relPath);
        foreach ($actions as $method) {
            expect($sweep)->toHaveKey($class.'::'.$method);
        }

        // `app/` on one side, every other declared root on the other — both descendable now, and the
        // split is what keeps the sweep from being one directory's story.
        str_starts_with($relPath, 'app/')
            ? $inside[$relPath] = count($actions)
            : $outside[$relPath] = count($actions);
    }

    expect($inside)->not->toBeEmpty()
        ->and($outside)->not->toBeEmpty()
        ->and(array_sum($inside))->toBeGreaterThan(30)
        ->and(array_sum($outside))->toBeGreaterThan(1)
        ->and(count($sweep))->toBe(array_sum($inside) + array_sum($outside));
})->group('fixture');

/**
 * The union of the four columns against the domain they divide. Every swept action either publishes a
 * status, publishes one nothing read, publishes nothing because every throw was demoted, or surfaces no
 * throw at all — and the two directions above only see the second. An action owing no answer to them
 * therefore has to land in a column that DOES ask something of it, rather than in the gap between two
 * scans that each cover their own subset.
 *
 * So the union is asserted as MEMBERSHIP of the four named columns, and every classification the four
 * do not cover is given a name of its own rather than a `default` that absorbs it. Counting instead
 * proves nothing: `array_sum(array_count_values($x)) === count($x)` is arithmetic, true of every input,
 * and stays true when a fifth column appears.
 */
it('lands every swept action in exactly one column', function (): void {
    $columns = [];
    foreach (unplacedSweep() as $action => $sides) {
        $columns[$action] = unplacedColumn($sides);
    }

    $named = unplacedColumnNames();

    // The union against the domain: every swept action, in one of the four columns the guards above
    // divide it into, and the offenders named rather than counted.
    expect(array_keys(array_diff($columns, $named)))->toBe([])
        ->and(array_keys($columns))->toBe(array_keys(unplacedSweep()));

    $counts = array_count_values($columns);

    // Each column really holds something: a classification nothing lands in proves nothing about it, and
    // three of the four are populations this file exists to keep visible.
    expect($counts['placed'] ?? 0)->toBeGreaterThan(10)
        ->and($counts['unplaced'] ?? 0)->toBeGreaterThan(10)
        ->and($counts['unsurfaced'] ?? 0)->toBeGreaterThan(0)
        ->and($counts['demoted'] ?? 0)->toBeGreaterThan(0);

    // And the two columns with a ledger hold exactly it — a new member fails until somebody writes down
    // why the document may carry nothing for that action.
    expect(array_keys(array_filter($columns, static fn (string $column): bool => $column === 'unsurfaced')))
        ->toBe(array_keys(unsurfacedThrowActions()))
        ->and(array_keys(array_filter($columns, static fn (string $column): bool => $column === 'demoted')))
        ->toBe(array_keys(demotedThrowActions()));
})->group('fixture');

/**
 * The unsurfaced ledger checked rather than believed. A throw may only vanish where the path to it crosses
 * a boundary this build DECLARES — an arrow function, which PHPStan hands over with no statement result,
 * or a callee outside the descend scope, which descent is bounded by on purpose. So each row has to name
 * one of those in the action's own source; an action written entirely inside the descend scope with no
 * arrow function in it has no excuse available, and a silent one there is a defect rather than a bound.
 *
 * The descend scope is the roots the application SHIPS, so "outside it" now means a root declared only in
 * `autoload-dev` — a modular `Modules\…` root is inside it, and the two rows this ledger used to carry for
 * one are gone because the throws they hid are published. That is why the boundary is read off the
 * autoload map rather than spelled as `app/`: written as a directory name, the predicate would go on
 * excusing a silence the build stopped having.
 *
 * The predicate is necessary, not sufficient — an action can name a modular class and still publish, and
 * one does — so it is held against an action with neither boundary, which must fail it.
 */
it('excuses an unsurfaced throw only where a declared boundary is crossed', function (): void {
    $ledger = unsurfacedThrowActions();
    expect($ledger)->not->toBeEmpty();

    $crosses = static function (string $action): bool {
        [$class, $method] = explode('::', $action, 2);
        $relPath = fixtureDeclarationFile($class);
        if ($relPath === null) {
            return false;
        }

        $source = throwActionSource($relPath, $method);
        expect($source)->not->toBe('');

        if (str_contains($source, 'fn (') || str_contains($source, 'fn(')) {
            return true;
        }

        // A type the action names that the application declares outside the descend scope: the callee
        // whose body descent is not entitled to read.
        foreach (fixtureNamesIn($relPath, $source) as $named) {
            $declaredIn = fixtureDeclarationFile($named);
            if ($declaredIn !== null && ! fixtureDescends($declaredIn)) {
                return true;
            }
        }

        return false;
    };

    foreach ($ledger as $action => $reason) {
        expect($crosses($action))->toBeTrue()
            ->and($reason)->not->toBe('');
    }

    // The predicate answering "yes" to everything would pass the loop above without proving anything:
    // this action throws in its own body, inside the descend scope, and really is surfaced.
    expect($crosses('App\\Http\\Controllers\\ThrowsController::manifestStatusAtAction'))->toBeFalse();
})->group('fixture');

/**
 * The demoted ledger checked rather than believed. `ThrowSignal` calls a throw plumbing only where the
 * application declared none of it — not a literal `throw`, not a `@throws` its own code wrote, wherever in
 * its own source that is. So a demoted class the application DECLARES is a real error filed as plumbing
 * and carried nowhere, which is the one answer this row may not excuse.
 */
it('demotes only a class the application does not declare', function (): void {
    $ledger = demotedThrowActions();
    expect($ledger)->not->toBeEmpty();

    $sweep = unplacedSweep();
    foreach ($ledger as $action => $fqcn) {
        expect($sweep)->toHaveKey($action)
            ->and(array_keys($sweep[$action]['demoted']))->toBe([$fqcn])
            ->and(fixtureDeclaresClass($fqcn))->toBeFalse();
    }

    // Every demotion anywhere in the sweep, not only the ledger's own rows: the rule is about the class,
    // so a second action demoting an application class fails here even with no row of its own.
    foreach ($sweep as $action => $sides) {
        foreach (array_keys($sides['demoted']) as $fqcn) {
            expect(fixtureDeclaresClass($fqcn))->toBeFalse("$action demotes $fqcn, which the application declares");
        }
    }
})->group('fixture');
