<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Throwing\SkippedDescent;
use Docuccino\Inference\PhpStan\Throwing\SkippedDescents;

/**
 * The published half of "descent stopped at a file the application declares": what the records turn
 * into, and the two properties a document embedding them depends on — a path off the build machine, and
 * an order that is a function of the records rather than of the walk.
 *
 * Which records are KEPT is answered here, because it is a question about directories rather than about
 * a PHPStan scope: only a hop the declared scope would have opened, so the remedy the notice publishes
 * really reaches the file it names. Whether the analyser reaches this recorder at all is the real
 * engine's question ({@see NarrowedDescendScopeTest}).
 */
/** A recorder whose declared scope is `$roots`, with the identity normaliser the engine's own uses. */
function skippedDescentsUnder(string ...$roots): SkippedDescents
{
    return new SkippedDescents(new ProjectFilter(array_values($roots), static fn (string $path): string => $path));
}

function skippedDescentOf(string $class, string $method, string $calleeFile, string $file, int $line): SkippedDescent
{
    return new SkippedDescent($class, $method, $calleeFile, new SourceLocation($file, $line));
}

it('publishes one notice naming the callee, the call site and the file it stopped at', function (): void {
    $records = skippedDescentsUnder('/checkout/app', '/checkout/modules', '/checkout/domain');
    $records->record(skippedDescentOf(
        'Modules\\Billing\\LedgerReviewQuery',
        'results',
        '/checkout/modules/Billing/LedgerReviewQuery.php',
        '/checkout/app/Http/Controllers/LedgerController.php',
        41,
    ));

    $diagnostics = $records->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/checkout')));

    expect($diagnostics)->toHaveCount(1);

    $only = $diagnostics[0];
    expect($only)->toBeInstanceOf(Diagnostic::class)
        // The recovery rung, not the defect one: bounding descent is a correct outcome, so this must
        // never be what fails somebody's severity gate.
        ->and($only->severity)->toBe(Severity::Info)
        ->and($only->code)->toBe('inference.descend-scope-narrowed')
        ->and($only->message)->toContain('Modules\\Billing\\LedgerReviewQuery::results()')
        ->and($only->message)->toContain('app/Http/Controllers/LedgerController.php:41')
        ->and($only->message)->toContain('modules/Billing/LedgerReviewQuery.php')
        // The remedy names the setting and the reader's two ways out of it.
        ->and($only->help)->toContain('engine.project_paths')
        ->and($only->help)->toContain('composer.json');
});

/**
 * A diagnostic is embedded in the document, so neither path it names may be one off the machine that
 * built it — and this notice names two, which is a second place the crossing can be forgotten.
 */
it('names both the call site and the skipped file as paths off the build machine', function (): void {
    $records = skippedDescentsUnder('/home/ci/checkout/domain');
    $records->record(skippedDescentOf(
        'Domain\\Orders\\Lookup',
        'find',
        '/home/ci/checkout/domain/Orders/Lookup.php',
        '/home/ci/checkout/app/Http/Controllers/OrderController.php',
        18,
    ));

    $message = $records->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/home/ci/checkout')))[0]->message;

    expect($message)->toContain('app/Http/Controllers/OrderController.php:18')
        ->and($message)->toContain('domain/Orders/Lookup.php')
        ->and($message)->not->toContain('/home/ci/checkout');
});

it('orders the notices by the records rather than by the order they arrived', function (): void {
    $sites = [
        skippedDescentOf('Modules\\Billing\\Second', 'run', '/checkout/modules/Billing/Second.php', '/checkout/app/A.php', 30),
        skippedDescentOf('Modules\\Billing\\First', 'run', '/checkout/modules/Billing/First.php', '/checkout/app/A.php', 12),
        skippedDescentOf('Modules\\Billing\\Third', 'run', '/checkout/modules/Billing/Third.php', '/checkout/app/B.php', 7),
    ];

    $labels = new MessagePaths(new RootRelativeSourcePathResolver('/checkout'));

    $forwards = skippedDescentsUnder('/checkout/app', '/checkout/modules', '/checkout/domain');
    foreach ($sites as $site) {
        $forwards->record($site);
    }

    $backwards = skippedDescentsUnder('/checkout/app', '/checkout/modules', '/checkout/domain');
    foreach (array_reverse($sites) as $site) {
        $backwards->record($site);
    }

    $messages = static fn (SkippedDescents $records): array => array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        $records->diagnostics($labels),
    );

    expect($messages($forwards))->toHaveCount(3)
        ->and($messages($forwards))->toBe($messages($backwards));
});

it('keeps one record per callee and call site however many paths reach it', function (): void {
    // A helper called from two branches of one action is one thing to go and fix, and descent meets the
    // same call once per analysed path.
    $records = skippedDescentsUnder('/checkout/app', '/checkout/modules', '/checkout/domain');
    foreach (range(1, 4) as $ignored) {
        $records->record(skippedDescentOf(
            'Modules\\Billing\\LedgerReviewQuery',
            'results',
            '/checkout/modules/Billing/LedgerReviewQuery.php',
            '/checkout/app/Http/Controllers/LedgerController.php',
            41,
        ));
    }

    // Two call sites for one callee stay two, though: each is a line the reader looks at.
    $records->record(skippedDescentOf(
        'Modules\\Billing\\LedgerReviewQuery',
        'results',
        '/checkout/modules/Billing/LedgerReviewQuery.php',
        '/checkout/app/Http/Controllers/LedgerController.php',
        58,
    ));

    expect($records->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/checkout'))))->toHaveCount(2);
});

it('has nothing to say about an analysis descent never stopped short in', function (): void {
    expect(skippedDescentsUnder('/checkout/app')->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/checkout'))))->toBe([]);
});

it('keeps only a call the declared scope would have opened', function (): void {
    // The two sub-populations of "descent declined a file the application declares". A shipped root
    // outside the configured scope is the reader's own narrowing and they can undo it; a root the
    // DEFAULT excludes — a test root, which is never API surface — is this engine's containment, and a
    // notice about it would fire on a build nobody configured and ask for an edit nobody should make.
    $records = skippedDescentsUnder('/checkout/app', '/checkout/modules');

    $records->record(skippedDescentOf(
        'Modules\\Billing\\LedgerReviewQuery',
        'results',
        '/checkout/modules/Billing/LedgerReviewQuery.php',
        '/checkout/app/Http/Controllers/LedgerController.php',
        41,
    ));
    $records->record(skippedDescentOf(
        'Tests\\Support\\SeedHelper',
        'seed',
        '/checkout/tests/Support/SeedHelper.php',
        '/checkout/app/Http/Controllers/LedgerController.php',
        58,
    ));
    // Nor vendor, which reaches the recorder the same way now the analyzer stopped pre-filtering.
    $records->record(skippedDescentOf(
        'Vendor\\Package\\Client',
        'send',
        '/checkout/vendor/acme/package/src/Client.php',
        '/checkout/app/Http/Controllers/LedgerController.php',
        62,
    ));

    $messages = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        $records->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/checkout'))),
    );

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('modules/Billing/LedgerReviewQuery.php');
});

it('publishes nothing at all when no declared scope was named', function (): void {
    // A host that offers no yardstick cannot tell its own narrowing from the engine's containment, so
    // it gets no notices rather than one per declined hop.
    $records = skippedDescentsUnder();
    $records->record(skippedDescentOf(
        'Modules\\Billing\\LedgerReviewQuery',
        'results',
        '/checkout/modules/Billing/LedgerReviewQuery.php',
        '/checkout/app/Http/Controllers/LedgerController.php',
        41,
    ));

    expect($records->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/checkout'))))->toBe([]);
});
