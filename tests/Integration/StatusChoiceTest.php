<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A status chosen between constants in one expression, in every place a response takes its status. The
 * server sends each of them, exactly as the same endpoint written as two returns does, so each reader
 * carries every code — and a status nothing can read replaces the one the receiver carried rather than
 * letting it stand.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function statusChoiceAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/StatusChoiceController.php',
        'App\\Http\\Controllers\\StatusChoiceController',
        ['chain', 'json', 'constructed', 'branches', 'upsert', 'requested', 'requestedJson', 'emptied', 'refused', 'helperDefault', 'helperPassed', 'helperChoice', 'constructedDefault', 'constructedPassed'],
    );
}

/** @return list<DType> the status argument of each return site */
function statusChoiceStatuses(string $method): array
{
    $statuses = [];
    foreach (ActionAnalysis::fromArray(statusChoiceAnalyses()[$method])->returns as $return) {
        $type = $return->type;
        expect($type)->toBeInstanceOf(ClassT::class)->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse');
        $statuses[] = $type->typeArgs[1] ?? new UnknownT('no status argument');
    }

    return $statuses;
}

it('carries every constant a status can be, whichever reader states it', function (string $method, array $codes): void {
    $expected = UnionT::of(array_map(static fn (int $code): LiteralT => new LiteralT($code), $codes));

    expect(statusChoiceStatuses($method))->toEqual([$expected]);
})->with([
    'a chained setStatusCode($ok ? 200 : 503)' => ['chain', [200, 503]],
    'response()->json($body, $ok ? 200 : 503)' => ['json', [200, 503]],
    'new JsonResponse($body, $ok ? 200 : 503)' => ['constructed', [200, 503]],
    // The resource the framework renders, under the code the chain chose between.
    'a rendered resource, ->setStatusCode($created ? 201 : 200)' => ['upsert', [200, 201]],
    'noContent($reset ? 205 : 204)' => ['emptied', [204, 205]],
    // A success helper taking its status as a parameter: the call site's code is the status, and a call
    // passing none sends the parameter's default — both through `response()->json()` and the constructor.
    '$this->ok($data), json() under a defaulted parameter' => ['helperDefault', [200]],
    '$this->ok($data, 201)' => ['helperPassed', [201]],
    '$this->ok($data, $fresh ? 201 : 200)' => ['helperChoice', [200, 201]],
    '$this->respond($data), the constructor under a defaulted parameter' => ['constructedDefault', [202]],
    '$this->respond($data, 201)' => ['constructedPassed', [201]],
])->group('fixture');

it('matches the same endpoint written as two returns', function (): void {
    expect(statusChoiceStatuses('branches'))->toEqual([new LiteralT(200), new LiteralT(503)]);
})->group('fixture');

it('invents no status it cannot read', function (string $method): void {
    // `->setStatusCode($request->integer('code'))` replaced the 200 `response()->json()` carried; no code
    // is left to claim, so none is — neither that 200 nor any other.
    $status = statusChoiceStatuses($method)[0];

    expect($status)->not->toBeInstanceOf(LiteralT::class)
        ->and($status)->not->toBeInstanceOf(UnionT::class);
})->with(['requested', 'requestedJson'])->group('fixture');

it('degrades a thrown status chosen between constants to no status, with a notice', function (): void {
    // The throw side carries one status per exception, so `abort($gone ? 410 : 404)` states no single one.
    // It is left unclaimed and said so — true, and vaguer than the two codes the call names.
    $analysis = statusChoiceAnalyses()['refused'];
    $codes = array_map(static fn (array $d): string => (string) $d['code'], $analysis['diagnostics']);

    expect($analysis['throws'][0])->toHaveKey('httpStatusHint')
        ->and($analysis['throws'][0]['httpStatusHint'])->toBeNull()
        ->and($codes)->toContain('inference.http-exception-status-unread');
})->group('fixture');
