<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine coverage for response-shape refinement through project-code helper indirection: the
 * engine follows an invokable renderer's `match (true)` arms into private methods and a static
 * `ProblemResponse::make()` helper whose declared bare `JsonResponse` return erased the shape,
 * recovering per-arm status, payload shape and content type. A stub can't stand in for this; each
 * test pins one refinement shape the capability has to handle.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/**
 * @return array{returns: list<array<string, mixed>>, deps: list<string>, diagnostics: list<string>}
 */
function refineInvoke(string $narrowType): array
{
    $analysis = FixtureRunner::analyzeCallable(
        'app/Exceptions/InvoiceProblemRenderer.php',
        'App\\Exceptions\\InvoiceProblemRenderer',
        '__invoke',
        param: 'e',
        narrowType: $narrowType,
    );

    return [
        'returns' => $analysis['returns'],
        'deps' => array_map(static fn (string $p): string => basename($p), $analysis['dependencyFiles']),
        'diagnostics' => array_map(static fn (array $d): string => (string) $d['code'], $analysis['diagnostics']),
    ];
}

/** The recovered `JsonResponse` shape from a single-return refinement: [status, contentType, payload keys]. */
function invokeShape(string $narrowType): array
{
    $result = refineInvoke($narrowType);
    $analysis = ActionAnalysis::fromArray(['returns' => $result['returns']]);
    expect($analysis->returns)->toHaveCount(1);

    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse');

    $statusArg = $type->typeArgs[1] ?? null;
    $status = $statusArg instanceof LiteralT && is_int($statusArg->value) ? $statusArg->value : null;

    $ctArg = $type->typeArgs[2] ?? null;
    $contentType = $ctArg instanceof LiteralT && is_string($ctArg->value) ? $ctArg->value : null;

    $payload = $type->typeArgs[0] ?? null;
    expect($payload)->toBeInstanceOf(ArrayShapeT::class);

    $keys = [];
    $members = [];
    foreach ($payload->fields as $field) {
        $keys[] = (string) $field->key;
        $members[(string) $field->key] = $field->type;
    }

    return ['status' => $status, 'contentType' => $contentType, 'keys' => $keys, 'members' => $members, 'deps' => $result['deps']];
}

it('recovers a TWO-HOP helper chain: __invoke arm → private method → static make() (404)', function (): void {
    $shape = invokeShape('App\\Exceptions\\InvoiceNotFoundException');

    expect($shape['status'])->toBe(404)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail')
        // Cache soundness: the helper the shape was recovered through joins the dependency set.
        ->and($shape['deps'])->toContain('ProblemResponse.php');
})->group('fixture');

it('recovers a ONE-HOP helper call: __invoke arm → static make() directly (409)', function (): void {
    $shape = invokeShape('App\\Exceptions\\OrderConflictException');

    expect($shape['status'])->toBe(409)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail');
})->group('fixture');

it('recovers the 422 branch WITH the pointer-list errors member via a distinct helper', function (): void {
    $shape = invokeShape('Illuminate\\Validation\\ValidationException');

    expect($shape['status'])->toBe(422)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail', 'errors');
})->group('fixture');

it('recovers a DIRECT new JsonResponse(...) return with no hop (429)', function (): void {
    $shape = invokeShape('App\\Exceptions\\RateLimitedException');

    expect($shape['status'])->toBe(429)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail');
})->group('fixture');

it('recovers payload + content type but leaves an UNFOLDABLE status permissive', function (): void {
    // renderHttp passes $e->getStatusCode() (non-constant) through the helper's status parameter.
    $shape = invokeShape('Symfony\\Component\\HttpKernel\\Exception\\HttpException');

    expect($shape['status'])->toBeNull() // recovered as UnknownT, not guessed
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($shape['keys'])->toContain('type', 'title', 'status', 'detail');
})->group('fixture');

it('does NOT descend into a vendor producer (JsonResponse::fromJsonString) — shape stays bare', function (): void {
    $result = refineInvoke('Illuminate\\Http\\Exceptions\\HttpResponseException');
    $analysis = ActionAnalysis::fromArray(['returns' => $result['returns']]);

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    // A bare JsonResponse (no recovered typeArgs) — the vendor callee was declined, not folded.
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs)->toBe([])
        // and no vendor file leaked into the dependency set.
        ->and($result['deps'])->each->not->toContain('JsonResponse.php');
})->group('fixture');

it('reads a per-type null match arm as framework DELEGATION (no response, no fold failure)', function (): void {
    $result = refineInvoke('App\\Exceptions\\InvoiceDelegatedException');
    $analysis = ActionAnalysis::fromArray(['returns' => $result['returns']]);

    // The delegate arm returns null → a void/null return, not a JsonResponse. The mapper defers silently.
    expect($analysis->returns)->toHaveCount(1)
        ->and($analysis->returns[0]->type->kind())->toBeIn(['null', 'void']);
})->group('fixture');

it('maps an unmatched type to the default arm response (500)', function (): void {
    $shape = invokeShape('RuntimeException');

    expect($shape['status'])->toBe(500)
        ->and($shape['contentType'])->toBe('application/problem+json');
})->group('fixture');

it('the broad non-JSON early-out (return null) never shadows the per-type response arms', function (): void {
    // Every specific type still recovers its response despite the `if (! expectsJson) return null;`
    // early-out sitting first in source order — the delegation site is skipped for a response arm.
    foreach (['App\\Exceptions\\InvoiceNotFoundException' => 404, 'App\\Exceptions\\OrderConflictException' => 409] as $type => $status) {
        expect(invokeShape($type)['status'])->toBe($status);
    }
})->group('fixture');

/**
 * The recovered shape of one `App\Exceptions\RefinerEdgeCases` method: folded status, explicit content
 * type, body members, and the raw typeArgs (empty when nothing richer than the bare type came back).
 */
function edgeShape(string $method): array
{
    $analysis = ActionAnalysis::fromArray(['returns' => FixtureRunner::analyzeCallable(
        'app/Exceptions/RefinerEdgeCases.php',
        'App\\Exceptions\\RefinerEdgeCases',
        $method,
    )['returns']]);

    foreach ($analysis->returns as $return) {
        $type = $return->type;
        if (! $type instanceof ClassT || $type->fqcn !== 'Illuminate\\Http\\JsonResponse') {
            continue;
        }

        $statusArg = $type->typeArgs[1] ?? null;
        $ctArg = $type->typeArgs[2] ?? null;
        $payload = $type->typeArgs[0] ?? null;

        $members = [];
        if ($payload instanceof ArrayShapeT) {
            foreach ($payload->fields as $field) {
                $members[(string) $field->key] = $field->type;
            }
        }

        return [
            'status' => $statusArg instanceof LiteralT && is_int($statusArg->value) ? $statusArg->value : null,
            'contentType' => $ctArg instanceof LiteralT && is_string($ctArg->value) ? $ctArg->value : null,
            'members' => $members,
            'typeArgs' => $type->typeArgs,
        ];
    }

    return ['status' => null, 'contentType' => null, 'members' => [], 'typeArgs' => []];
}

it('folds a NARROWED enum variable, not just a written-out Enum::Case argument', function (): void {
    // The call site passes a variable PHPStan narrowed to exactly one case, so the fold has to read the
    // case off the scope's enum cases (the second enumCaseOf path) rather than a const-fetch.
    $shape = edgeShape('narrowedEnumVariable');

    expect($shape['status'])->toBe(409) // InvoiceProblem::Conflict->status(), folded through the variable
        ->and($shape['members']['type'])->toEqual(new LiteralT('https://errors.test/problems/conflict'))
        ->and($shape['members']['code'])->toEqual(new LiteralT('Conflict'));
})->group('fixture');

it('recovers the conditionally-appended body member when the caller passes a non-null $data', function (): void {
    // fromProblem()'s `if ($data !== null) { $body['data'] = $data; }` arm is dead for every other
    // caller (they all pass null). The appended member must show up alongside the folded per-case
    // members, widened rather than pinned — its value doesn't fold.
    $shape = edgeShape('conditionalAppend');

    expect($shape['status'])->toBe(403)
        ->and($shape['members'])->toHaveKey('data')
        ->and($shape['members']['data'])->not->toBeInstanceOf(LiteralT::class)
        // …and the folded per-case members are unaffected by the append.
        ->and($shape['members']['type'])->toEqual(new LiteralT('https://errors.test/problems/forbidden'));
})->group('fixture');

it('matches the Content-Type header case-insensitively, and recovers none when absent', function (string $method, ?string $contentType, int $status): void {
    $shape = edgeShape($method);

    expect($shape['contentType'])->toBe($contentType)
        ->and($shape['status'])->toBe($status);
})->with([
    // A lower-case key still yields the explicit media type…
    'lower-case content-type key' => ['lowercaseContentType', 'application/problem+json', 418],
    // …and with no headers argument nothing is recovered — the builder defaults it, we never guess.
    'no headers argument at all' => ['noHeaders', null, 422],
])->group('fixture');

it('declines a mutually recursive helper via the cycle guard (no runaway descent)', function (): void {
    // cyclicA() → cyclicB() → cyclicA(): the second visit hits the in-progress guard and declines, so
    // the shape stays bare instead of recursing forever and the analysis still completes.
    $shape = edgeShape('cyclicA');

    expect($shape['typeArgs'])->toBe([])
        ->and($shape['status'])->toBeNull();
})->group('fixture');

it('follows an error-render helper into a PRIMED modular root (prime-scope containment, not descend)', function (): void {
    // ModularRenderer (app/, descend + prime scope) → ModularProblemResponse::make (Modules\Billing,
    // primed but outside the descend scope). The refiner's containment gate is prime scope, so it folds
    // the modular helper's 451 shape; a descend-scoped gate would decline the module and leave it bare.
    $analysis = ActionAnalysis::fromArray(['returns' => FixtureRunner::analyzeCallable(
        'app/Exceptions/ModularRenderer.php',
        'App\\Exceptions\\ModularRenderer',
        'render',
    )['returns']]);

    expect($analysis->returns)->toHaveCount(1);
    $type = $analysis->returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class)->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse');

    $statusArg = $type->typeArgs[1] ?? null;
    expect($statusArg)->toEqual(new LiteralT(451)); // folded from the modular helper — proof it was followed

    $members = [];
    foreach (($type->typeArgs[0] ?? null)?->fields ?? [] as $field) {
        $members[(string) $field->key] = $field->type;
    }
    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/modular'));
})->group('fixture');

// Value-flow: per-arm literals fold into body members through the helper's parameters.

it('folds a ONE-HOP arm’s per-arm literals into the body members (409: type const + status literal)', function (): void {
    // OrderConflict → make('https://…/conflict', 'Conflict', 409, $e->getMessage()): the call-site
    // literals bind to make()'s $type/$title/$status parameters, so the recovered body carries them as
    // literals — which is what documents `type` as a const and `status` as 409. $detail doesn't fold
    // and stays widened; we never pin a value that doesn't flow to it.
    $members = invokeShape('App\\Exceptions\\OrderConflictException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/conflict'))
        ->and($members['title'])->toEqual(new LiteralT('Conflict'))
        ->and($members['status'])->toEqual(new LiteralT(409))
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('folds a TWO-HOP arm’s per-arm literals two hops out (404: type const + status literal)', function (): void {
    // NotFound → renderNotFound() → make('https://…/not-found', 'Not Found', 404, …): the literals live
    // at the innermost make() call and bind there, propagating fully resolved up to __invoke.
    $members = invokeShape('App\\Exceptions\\InvoiceNotFoundException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/not-found'))
        ->and($members['status'])->toEqual(new LiteralT(404));
})->group('fixture');

it('marks the status body member as a StatusMarkerT when the status does not fold (renderHttp)', function (): void {
    // renderHttp passes $e->getStatusCode() through make()'s $status parameter: the HTTP status stays
    // permissive, and the body `status` member — the same parameter — becomes a StatusMarkerT for the
    // response seam to fill with the documented status rather than a guess.
    $members = invokeShape('Symfony\\Component\\HttpKernel\\Exception\\HttpException')['members'];

    expect($members['status'])->toBeInstanceOf(StatusMarkerT::class)
        // The non-status literals passed to that arm still fold.
        ->and($members['type'])->toEqual(new LiteralT('about:blank'))
        ->and($members['title'])->toEqual(new LiteralT('HTTP Error'));
})->group('fixture');

it('keeps DIRECTLY-written body literals as literals (422: type/title/status const, dynamic members widened)', function (): void {
    // validation() writes type/title/status directly in the array (no parameter hop) so they recover as
    // literals; $detail and the $errors list are dynamic and stay widened.
    $members = invokeShape('Illuminate\\Validation\\ValidationException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/validation'))
        ->and($members['title'])->toEqual(new LiteralT('Unprocessable Entity'))
        ->and($members['status'])->toEqual(new LiteralT(422))
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class)
        ->and($members['errors'])->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

it('folds the direct-constructor arm’s literal body members (429)', function (): void {
    // RateLimited returns new JsonResponse([…all literals…], 429): every member folds, status included.
    $members = invokeShape('App\\Exceptions\\RateLimitedException')['members'];

    expect($members['type'])->toEqual(new LiteralT('https://errors.test/problems/rate-limited'))
        ->and($members['status'])->toEqual(new LiteralT(429));
})->group('fixture');

// Enum-case accessor folding: a bound case folds ->value / ->name / method accessors.

it('folds a bound enum case’s accessors into per-case literals + status (403, one hop)', function (): void {
    // InvoiceForbidden → fromProblem(InvoiceProblem::Forbidden, …): the case binds into the helper's
    // $problem parameter and its accessors fold — ->value (type URI), ->name (code), status()/title()
    // (match-method), docsUrl() (plain return) — while classify() (computed) and $detail stay permissive.
    $shape = invokeShape('App\\Exceptions\\InvoiceForbiddenException');
    $members = $shape['members'];

    expect($shape['status'])->toBe(403) // the folded status() drives the HTTP status (not the throw hint)
        ->and($shape['contentType'])->toBe('application/problem+json')
        ->and($members['type'])->toEqual(new LiteralT('https://errors.test/problems/forbidden'))
        ->and($members['code'])->toEqual(new LiteralT('Forbidden'))
        ->and($members['title'])->toEqual(new LiteralT('Forbidden'))
        ->and($members['status'])->toEqual(new LiteralT(403))
        ->and($members['docs'])->toEqual(new LiteralT('https://errors.test/docs'))
        ->and($members['kind'])->not->toBeInstanceOf(LiteralT::class) // computed body — never guessed
        ->and($members['detail'])->not->toBeInstanceOf(LiteralT::class)
        // Cache soundness: the enum whose methods were folded joins the dependency set.
        ->and($shape['deps'])->toContain('InvoiceProblem.php');
})->group('fixture');

it('folds a bound enum case through a TWO-hop re-home (404: missing)', function (): void {
    // InvoiceMissing → renderProblem(InvoiceProblem::NotFound, …) → fromProblem(…): accessor provenance
    // re-homes through renderProblem's parameter, then folds when the case binds at the outer call.
    $shape = invokeShape('App\\Exceptions\\InvoiceMissingException');
    $members = $shape['members'];

    expect($shape['status'])->toBe(404)
        ->and($members['type'])->toEqual(new LiteralT('https://errors.test/problems/missing'))
        ->and($members['code'])->toEqual(new LiteralT('NotFound'))
        ->and($members['title'])->toEqual(new LiteralT('Not Found'))
        ->and($members['status'])->toEqual(new LiteralT(404));
})->group('fixture');

it('folds a VENDOR enum’s ->value/->name but NEVER analyses its method (400)', function (): void {
    // fromOperator(FilterOperator::EQUAL): ->value and ->name fold via reflection (vendor-safe), but
    // isDynamic() is a vendor method the folder declines to analyse — the member stays permissive.
    $shape = invokeShape('App\\Exceptions\\InvoiceVendorEnumException');
    $members = $shape['members'];

    expect($shape['status'])->toBe(400)
        ->and($members['operator'])->toEqual(new LiteralT('='))
        ->and($members['label'])->toEqual(new LiteralT('EQUAL'))
        ->and($members['dynamic'])->not->toBeInstanceOf(LiteralT::class);
})->group('fixture');

/** The folded HTTP status of the single recovered return in a serialized analysis, or null when bare. */
function pairStatus(array $analysis): ?int
{
    $decoded = ActionAnalysis::fromArray(['returns' => $analysis['returns']]);
    $type = $decoded->returns[0]->type ?? null;
    if (! $type instanceof ClassT || $type->fqcn !== 'Illuminate\\Http\\JsonResponse') {
        return null;
    }
    $statusArg = $type->typeArgs[1] ?? null;

    return $statusArg instanceof LiteralT && is_int($statusArg->value) ? $statusArg->value : null;
}

// The [fileBudget, traceDepth] pairs below truncate the BudgetRenderer chain's deep path while leaving
// its direct path intact — a file budget of 2 (BudgetPad + BudgetShared spend it, so the BudgetLeaf hop
// is refused) and a descent depth of 2 (BudgetLeaf sits one level too deep behind BudgetPad). Each drives
// a different refuse-to-descend branch, and the memo has to respect both.

it('never reuses a bound-truncated helper shape where a later analysis had headroom (determinism guard)', function (int $fileBudget, int $traceDepth): void {
    // One engine, one bound shrunk: deep() spends it through BudgetPad, so the BudgetLeaf hop is cut off
    // and BudgetShared::make() recovers a truncated (bare) shape first. direct() then reaches the same
    // helper with headroom and must get the full 418 shape — the refiner never memoises a truncated
    // computation. Without that rule this is a latent route-order nondeterminism.
    $pair = FixtureRunner::refinePair(
        $fileBudget,
        $traceDepth,
        ['app/Support/BudgetRenderer.php', 'App\\Support\\BudgetRenderer', 'deep'],
        ['app/Support/BudgetRenderer.php', 'App\\Support\\BudgetRenderer', 'direct'],
    );

    expect(pairStatus($pair['first']))->toBeNull()   // deep path: BudgetLeaf hop cut off → bare
        ->and(pairStatus($pair['second']))->toBe(418); // direct path: full shape, not the stale truncation
})->with(['file budget' => [2, 4], 'descent depth' => [40, 2]])->group('fixture');

it('never serves a complete helper shape to an analysis that had no headroom to earn it', function (int $fileBudget, int $traceDepth): void {
    // The mirror image: direct() goes FIRST and memoises the full 418 shape for BudgetShared::make(),
    // then deep() reaches the same helper with the bound already spent. Serving the memo there would hand
    // deep() a body it could not have computed — the same route-order dependence from the other side, and
    // a warm/cold divergence too, since a cold build of deep() alone can only produce the bare type.
    $pair = FixtureRunner::refinePair(
        $fileBudget,
        $traceDepth,
        ['app/Support/BudgetRenderer.php', 'App\\Support\\BudgetRenderer', 'direct'],
        ['app/Support/BudgetRenderer.php', 'App\\Support\\BudgetRenderer', 'deep'],
    );

    expect(pairStatus($pair['first']))->toBe(418)    // direct path: full shape, memoised
        ->and(pairStatus($pair['second']))->toBeNull(); // deep path: starved, so it must NOT get the memo
})->with(['file budget' => [2, 4], 'descent depth' => [40, 2]])->group('fixture');

it('analyses a pair to identical results whichever one runs first', function (int $fileBudget, int $traceDepth): void {
    // The whole claim: whatever the bounds do to this chain, an analysis is a function of the callable
    // alone. Returns, diagnostics and dependency files all have to match across the orders — a
    // dependency file that only appears when a memo was warm would break the fragment cache the same way
    // a shape would.
    $deep = ['app/Support/BudgetRenderer.php', 'App\\Support\\BudgetRenderer', 'deep'];
    $direct = ['app/Support/BudgetRenderer.php', 'App\\Support\\BudgetRenderer', 'direct'];

    $forward = FixtureRunner::refinePair($fileBudget, $traceDepth, $deep, $direct);
    $reverse = FixtureRunner::refinePair($fileBudget, $traceDepth, $direct, $deep);

    // Both analyses recovered a return site, so "identical" can't be satisfied by two empty results.
    expect($forward['first']['returns'])->toHaveCount(1)
        ->and($forward['second']['returns'])->toHaveCount(1)
        ->and($forward['first'])->toEqual($reverse['second'])
        ->and($forward['second'])->toEqual($reverse['first']);
})->with(['file budget' => [2, 4], 'descent depth' => [40, 2], 'real defaults' => [40, 4]])->group('fixture');

it('reports a bound-truncated response shape instead of degrading quietly', function (): void {
    // A response that lost its body to the descent bound is documented as its declared type — true, but
    // poorer than the code says, so the analysis carries the reason. The path that recovered the full
    // shape must stay silent, or the diagnostic says nothing.
    $pair = FixtureRunner::refinePair(
        2,
        4,
        ['app/Support/BudgetRenderer.php', 'App\\Support\\BudgetRenderer', 'deep'],
        ['app/Support/BudgetRenderer.php', 'App\\Support\\BudgetRenderer', 'direct'],
    );

    $codes = static fn (array $analysis): array => array_map(
        static fn (array $diagnostic): string => (string) $diagnostic['code'],
        $analysis['diagnostics'],
    );

    expect($codes($pair['first']))->toContain('inference.response-shape-truncated')
        ->and($codes($pair['second']))->not->toContain('inference.response-shape-truncated');
})->group('fixture');

it('folds each case independently + deterministically (memoisation keyed per enum-case+method)', function (): void {
    // The same helper + enum methods reached for two cases fold to each case's own literals — the fold
    // is memoised per enum-case+method, so nothing leaks across cases — and repeats are identical.
    $forbidden = invokeShape('App\\Exceptions\\InvoiceForbiddenException')['members'];
    $missing = invokeShape('App\\Exceptions\\InvoiceMissingException')['members'];
    $forbiddenAgain = invokeShape('App\\Exceptions\\InvoiceForbiddenException')['members'];

    expect($forbidden['status'])->toEqual(new LiteralT(403))
        ->and($missing['status'])->toEqual(new LiteralT(404))
        ->and($forbidden['title'])->toEqual(new LiteralT('Forbidden'))
        ->and($missing['title'])->toEqual(new LiteralT('Not Found'))
        ->and($forbiddenAgain)->toEqual($forbidden);
})->group('fixture');

it('does not record a conditionally-supplied member no call site can settle', function (): void {
    // `instance: TraceContext::id() ?? new Optional` is written at the `new`, but writing it is not the
    // same as supplying it: the fallback is what renders when the static read answers null, and the left
    // side roots in no parameter, so binding has nothing to resolve it against. Recording it would tell the
    // adapter this response carries a member half its runs omit — and the adapter publishes that as an
    // example. The four arguments that DO settle are unaffected.
    $members = [];
    foreach ((edgeShape('unbindableOptionalMember')['typeArgs'][3] ?? null)?->fields ?? [] as $field) {
        $members[(string) $field->key] = $field->type;
    }

    expect($members)->toHaveKeys(['type', 'title', 'status', 'detail'])
        ->and($members['status'])->toEqual(new LiteralT(424))
        ->and($members['type'])->toEqual(new LiteralT('https://errors.test/problems/traced'))
        ->and($members)->not->toHaveKey('instance')
        ->and($members)->not->toHaveKey('errors');
})->group('fixture');
