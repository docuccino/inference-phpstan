<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * The throw-analysis scorecard against the real engine: abort status folding, registry
 * enrichment + rescue, bounded descent, `@throws` trust, catch subtraction, and
 * exception identity by (fqcn, status).
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array<string, mixed>
 */
function throwsAnalysis(string $method): array
{
    return FixtureRunner::analyze(
        'app/Http/Controllers/ThrowsController.php',
        'App\\Http\\Controllers\\ThrowsController',
        $method,
    );
}

/**
 * @return list<string> signal-disposition exceptions as "ShortName@status"
 */
function signalThrows(string $method): array
{
    $analysis = throwsAnalysis($method);

    $out = [];
    /** @var list<array<string, mixed>> $throws */
    $throws = $analysis['throws'];
    foreach ($throws as $throw) {
        if (($throw['disposition'] ?? null) !== 'signal') {
            continue;
        }
        $fqcn = (string) $throw['exceptionFqcn'];
        $pos = strrpos($fqcn, '\\');
        $short = $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
        $out[] = $short.'@'.($throw['httpStatusHint'] ?? 'null');
    }
    sort($out);

    return $out;
}

dataset('throw cases', [
    'abort + abort_if, both statuses folded' => ['abortAction', ['HttpException@403', 'HttpException@404']],
    // The same two calls with the status named rather than counted. PHPStan hands throw points the
    // NORMALIZED call, so a named argument already sits in the position the registry indexes — pinned
    // here because the day that stops being true, both statuses vanish without a word.
    'abort + abort_if, statuses named' => ['namedAbortAction', ['HttpException@418', 'HttpException@451']],
    'authorize → 403' => ['authorizeAction', ['AuthorizationException@403']],
    'static findOrFail rescued → 404' => ['findOrFailAction', ['ModelNotFoundException@404']],
    'inline validate → 422' => ['validateAction', ['ValidationException@422']],
    '2-deep descent, no @throws' => ['deepUndeclared', ['OutOfStockException@500', 'RuntimeException@500']],
    '@throws trusted, deeper hidden' => ['deepDeclared', ['OutOfStockException@500']],
    'vendor any-throwable = no API error' => ['anyThrowableNoise', []],
    'caught subtracted, escaping surfaced' => ['tryCatch', ['RuntimeException@500']],
    // The registry is keyed on a bare method name, so an app's own validate() is exactly where a guess
    // could overrule a truth: the callee is project code we read, so its own exception stands and no
    // ValidationException/422 is invented for it.
    "the app's own validate() keeps its own exception" => ['projectValidate', ['OutOfStockException@500']],
    // An exception that IS an HTTP status states it in its own constructor, where no name-keyed table can
    // see it. Each row is one of the shapes an application writes that in, and 500 — the answer a lookup
    // miss used to give all three — would be a failure the server does not have.
    'a status pinned through a private constructor default' => ['pinnedHttpStatus', ['ExportRejectedException@422']],
    'a status pinned as a literal two classes up' => ['inheritedHttpStatus', ['PortalUnavailableException@503']],
    'a status written at the throw, no constructor of its own' => ['httpStatusAtThrowSite', ['ExportLockedException@423']],
    'a status written at the throw, its argument NAMED' => ['namedHttpStatusAtThrowSite', ['ExportLockedException@423']],
    // The same default behind a PUBLIC constructor is no pin for the CLASS — any caller may pass another —
    // and it is still what THIS construction passes, because it left the slot empty. The pair below is the
    // point: the same `new`, one hop apart, and a document that answered them differently.
    'a public constructor default, at the throw' => ['defaultedHttpStatusAtThrowSite', ['ExportBlockedException@409']],
    'the same construction inside the factory the throw names' => ['defaultedHttpStatusInFactory', ['ExportBlockedException@409']],
    // A constructor that normalises the status it was handed: `none()` really builds a 400, so the 422 the
    // default names is a status the code does not state and no status at all is the honest answer.
    'a constructor that moves the status it was handed' => ['movedHttpStatus', ['ExportPartialException@null']],
    // …and the same defect one statement later, which is the row the FOLD SCOPE decides: folding the
    // forwarded argument in the body's end scope answers the 500 assigned after the parent call, a status
    // nothing was ever built with. Folding it at the call, where it is written, answers nothing at all.
    'a constructor that reuses the status after forwarding it' => ['supersededHttpStatus', ['ExportSupersededException@null']],
    // Nothing folds, which is what the diagnostic below is raised for.
    'a factory that builds the class two ways' => ['unreadHttpStatus', ['ExportConflictException@null']],
    // A vendor exception the application throws itself is still the application's error; its status is
    // written where PHPStan strips the body, so it is documented without one rather than at a made-up 500.
    'a vendor exception thrown deliberately' => ['vendorHttpStatusAtThrowSite', ['ConflictHttpException@null']],
    // …and one only DECLARED by a vendor method is plumbing: being an HttpException subclass is not itself
    // a status, so nothing here is promoted to an API error.
    'a vendor @throws of a vendor HttpException subclass' => ['vendorDeclaredHttpStatus', []],
    // A class that pins nothing because its FACTORIES choose. The throw names one, and the class constant
    // it builds with folds through the factory's own scope like the literal beside it would.
    'a status the factory named at the throw builds with' => ['factoryHttpStatus', ['ExportUnsupportedException@422']],
    // The pair that makes the point: one class, two factories, two statuses, on two operations. A reader of
    // the class alone can only answer null for both, and answering 500 would invent a failure twice over.
    'one factory of a two-status class' => ['factoryDefaultedStatus', ['ExportConflictException@409']],
    'its sibling, the same class at another status' => ['factoryOverriddenStatus', ['ExportConflictException@403']],
]);

it('surfaces exactly the expected API errors', function (string $method, array $expected): void {
    sort($expected);

    expect(signalThrows($method))->toBe($expected);
})->with('throw cases')->group('fixture');

/**
 * @return list<string> the messages of every unread-HTTP-status notice the analysis raised
 */
function unreadStatusDiagnostics(string $method): array
{
    /** @var list<array<string, mixed>> $diagnostics */
    $diagnostics = throwsAnalysis($method)['diagnostics'];

    $out = [];
    foreach ($diagnostics as $diagnostic) {
        if (($diagnostic['code'] ?? null) === 'inference.http-exception-status-unread') {
            $out[] = (string) $diagnostic['message'];
        }
    }

    return $out;
}

it('names the class whose HTTP status it could not read', function (string $method, string $fqcn): void {
    $reported = unreadStatusDiagnostics($method);

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toContain($fqcn);
})->with([
    'a factory that builds the class two ways' => ['unreadHttpStatus', 'App\\Exceptions\\ExportConflictException'],
    'a constructor that moves the status it was handed' => ['movedHttpStatus', 'App\\Exceptions\\ExportPartialException'],
    'a constructor that reuses the status after forwarding it' => ['supersededHttpStatus', 'App\\Exceptions\\ExportSupersededException'],
])->group('fixture');

it('says nothing where the status read, and nothing about a class the author does not own', function (string $method): void {
    expect(unreadStatusDiagnostics($method))->toBe([]);
})->with([
    'pinnedHttpStatus',
    'inheritedHttpStatus',
    'httpStatusAtThrowSite',
    'namedHttpStatusAtThrowSite',
    'defaultedHttpStatusAtThrowSite',
    'defaultedHttpStatusInFactory',
    'factoryHttpStatus',
    'factoryDefaultedStatus',
    'factoryOverriddenStatus',
    // The two vendor shapes: the status is unread in both, and the remedy the notice names is an edit to
    // `vendor/` — the non-actionable firing that trains a reader to ignore the channel.
    'vendorHttpStatusAtThrowSite',
    'vendorDeclaredHttpStatus',
    // And nothing for a plain domain exception either: it is not an HttpException, so there is no status
    // on it to have failed to read.
    'deepUndeclared',
])->group('fixture');

it('answers the same status whether the construction is at the throw or one hop inside a factory', function (): void {
    // "Covering is not agreeing": the two spellings each had a row, and what neither asked was whether they
    // agree — they did not, by 409 against nothing at all. The rule is stated here rather than asked of the
    // code: `new ExportBlockedException` leaves the status slot empty, PHP fills it with the 409 written on
    // the constructor, and where the same `new` sits is not a fact about the response.
    expect(signalThrows('defaultedHttpStatusAtThrowSite'))
        ->toBe(signalThrows('defaultedHttpStatusInFactory'))
        ->and(signalThrows('defaultedHttpStatusAtThrowSite'))->toBe(['ExportBlockedException@409']);
})->group('fixture');

it('depends on the file the status was written in', function (): void {
    // Fragment-cache soundness: the status now comes out of the exception class, so editing it has to
    // rebuild every route that throws it — including the abstract base, where the next edit may put a
    // constructor that changes the answer.
    /** @var list<string> $files */
    $files = throwsAnalysis('inheritedHttpStatus')['dependencyFiles'];
    $names = array_map(static fn (string $file): string => basename($file), $files);

    expect($names)->toContain('PortalUnavailableException.php')
        ->and($names)->toContain('PortalException.php');
})->group('fixture');

it('depends on the file the factory was written in', function (): void {
    // The same soundness one hop on: the status this route publishes is now a fact of a factory body, so
    // that file has to be able to invalidate the route as well.
    /** @var list<string> $files */
    $files = throwsAnalysis('factoryOverriddenStatus')['dependencyFiles'];
    $names = array_map(static fn (string $file): string => basename($file), $files);

    expect($names)->toContain('ExportConflictException.php');
})->group('fixture');
