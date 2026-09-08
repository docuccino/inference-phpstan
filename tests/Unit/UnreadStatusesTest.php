<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatus;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatuses;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatusReason;

/**
 * The analyser's published half, driven in this process: what the records it collected turn into, and
 * what they may never turn into. The analysis that fills one runs in a subprocess, but nothing about a
 * PHPStan scope is involved in deciding which firings a reader hears about or in what order.
 */
function unreadStatusAt(string $file, int $line, UnreadStatusReason $reason, bool $inProject = true, string $fqcn = 'App\\Exceptions\\ExportConflictException'): UnreadStatus
{
    return new UnreadStatus($fqcn, $reason, new SourceLocation($file, $line), $inProject);
}

it('publishes the firings a reader can act on and drops the rest', function (): void {
    $records = new UnreadStatuses;

    // Same fold, same reason — the file it read is the whole difference.
    $records->record(unreadStatusAt('/checkout/app/Services/Export.php', 22, UnreadStatusReason::DynamicConstruction));
    $records->record(unreadStatusAt('/checkout/vendor/acme/pkg/src/Ship.php', 9, UnreadStatusReason::DynamicConstruction, inProject: false));
    // And a reason whose remedy is nobody's to take, in the application's own file.
    $records->record(unreadStatusAt('/checkout/app/Services/Export.php', 31, UnreadStatusReason::ForeignClass));

    $diagnostics = $records->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/checkout')));

    expect($diagnostics)->toHaveCount(1);

    $only = $diagnostics[0];
    expect($only)->toBeInstanceOf(Diagnostic::class)
        ->and($only->severity)->toBe(Severity::Info)
        ->and($only->code)->toBe('inference.http-exception-status-unread')
        ->and($only->help)->toBe(UnreadStatusReason::DynamicConstruction->remedy())
        ->and($only->message)->toContain('app/Services/Export.php:22');
});

/**
 * A diagnostic is embedded in the document, so the site it names may not be a path off the machine that
 * built it. The analysis hands the record a raw analyser path, and this is the crossing that makes it
 * publishable — dropping it leaves every notice byte-identical only to itself.
 */
it('names the throw site as a path off the build machine, never on it', function (): void {
    $records = new UnreadStatuses;
    $records->record(unreadStatusAt('/home/ci/checkout/app/Http/Controllers/ExportController.php', 18, UnreadStatusReason::DynamicArgument));

    $message = $records->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/home/ci/checkout')))[0]->message;

    expect($message)->toContain('app/Http/Controllers/ExportController.php:18')
        ->and($message)->not->toContain('/home/ci/checkout');
});

/**
 * Determinism is a product feature and locality is the other half of it: the notices an action gives
 * are a function of what was recorded, never of the order the analysis happened to meet the throws.
 */
it('orders the notices by the records rather than by the order they arrived', function (): void {
    $sites = [
        unreadStatusAt('/checkout/app/Services/Export.php', 31, UnreadStatusReason::DynamicConstruction),
        unreadStatusAt('/checkout/app/Services/Export.php', 22, UnreadStatusReason::DynamicConstruction),
        unreadStatusAt('/checkout/app/Http/Controllers/ExportController.php', 18, UnreadStatusReason::DynamicArgument, fqcn: 'Symfony\\Component\\HttpKernel\\Exception\\HttpException'),
    ];

    $labels = new MessagePaths(new RootRelativeSourcePathResolver('/checkout'));

    $forwards = new UnreadStatuses;
    foreach ($sites as $site) {
        $forwards->record($site);
    }

    $backwards = new UnreadStatuses;
    foreach (array_reverse($sites) as $site) {
        $backwards->record($site);
    }

    $messages = static fn (UnreadStatuses $records): array => array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        $records->diagnostics($labels),
    );

    expect($messages($forwards))->toHaveCount(3)
        ->and($messages($forwards))->toBe($messages($backwards));
});

it('keeps one record per exception, reason and site however many paths reach it', function (): void {
    $records = new UnreadStatuses;
    foreach (range(1, 4) as $ignored) {
        $records->record(unreadStatusAt('/checkout/app/Services/Export.php', 22, UnreadStatusReason::DynamicConstruction));
    }

    expect($records->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/checkout'))))->toHaveCount(1);
});

it('has nothing to say about an analysis that read every status', function (): void {
    expect((new UnreadStatuses)->diagnostics(new MessagePaths(new RootRelativeSourcePathResolver('/checkout'))))->toBe([]);
});
