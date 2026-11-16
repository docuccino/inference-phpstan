<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A response whose status is read off an enum case — `TokenRefusal::Expired->status()` — in every place a
 * response takes its status. An enum is how an application writes a reason and its status in one place, so
 * the number lives in the enum's `match ($this)` rather than at the response; the server sends the code the
 * case maps to, and a throw reads it the same way ({@see ThrowStatusTest}).
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function caseStatusResponseAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/CaseStatusResponseController.php',
        'App\\Http\\Controllers\\CaseStatusResponseController',
        [
            'json', 'constructed', 'chain', 'earlyReturn', 'constructedValue', 'noContent', 'agreeing', 'disagreeing',
            'chainDisagreeing', 'helperCase', 'helperAgreeing', 'helperDisagreeing', 'helperStatus',
            'constructedHelperStatus', 'helperValue', 'constant', 'chainConstant', 'helperValueJson',
            'helperValuePlain', 'helperNamed', 'helperValueChain', 'helperValueForwarded', 'helperCaseForwarded',
            'helperValueDisagreeing', 'valueDisagreeing', 'valueDisagreeingJson',
        ],
    );
}

/** The status argument of the one return site. */
function caseStatusResponseStatus(string $method): DType
{
    $returns = ActionAnalysis::fromArray(caseStatusResponseAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    $type = $returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class);

    return $type->typeArgs[1] ?? new UnknownT('no status argument');
}

/** @return list<string> */
function caseStatusResponseDependencies(string $method): array
{
    /** @var list<string> $files */
    $files = caseStatusResponseAnalyses()[$method]['dependencyFiles'];

    return array_map(static fn (string $file): string => basename($file), $files);
}

it('publishes the status the case maps to, wherever the response takes it', function (string $method, int $status): void {
    expect(caseStatusResponseStatus($method))->toEqual(new LiteralT($status));
})->with([
    'response()->json($body, Case->status())' => ['json', 401],
    'new JsonResponse($body, Case->status())' => ['constructed', 409],
    '->setStatusCode(Case->status())' => ['chain', 401],
    // The case's own arm sits past an early return for another case: the status is the return it reaches.
    '->setStatusCode(Case->httpStatus()), chosen by early return' => ['earlyReturn', 409],
    'new Response($body, Case->value)' => ['constructedValue', 410],
    'noContent(Case->statusCode())' => ['noContent', 410],
    // A helper handed the case, or the status read off it, sends what the call site's case maps to.
    '$this->refuse(Case), reading $reason->status()' => ['helperCase', 409],
    '$this->fail($body, Case->status())' => ['helperStatus', 401],
    '$this->send($body, Case->httpStatus()), the constructor' => ['constructedHelperStatus', 409],
    // The helper's parameter may be any case, so its own type holds every backing value; the call names one
    // case, and that case's value is the only status the server sends for it.
    '$this->fault(Case), reading $fault->value into new JsonResponse()' => ['helperValue', 410],
    '$this->faultJson(Case), reading $fault->value into response()->json()' => ['helperValueJson', 423],
    '$this->faultPlain(Case), reading $fault->value into response()' => ['helperValuePlain', 410],
    // Each hop hands the case on, so the case the outermost call names is the one the inner helper reads.
    'a helper forwarding the case to one reading $fault->value' => ['helperValueForwarded', 423],
    'a helper forwarding the case to one reading $reason->status()' => ['helperCaseForwarded', 409],
])->group('fixture');

it('publishes the body member a helper reads off the case it is handed', function (string $method, string $error): void {
    // `$fault->name` in the helper is either case's name; the call names one, and the body carries that one.
    $returns = ActionAnalysis::fromArray(caseStatusResponseAnalyses()[$method])->returns;
    $type = $returns[0]->type;
    expect($type)->toBeInstanceOf(ClassT::class);

    expect($type->typeArgs[0] ?? null)->toEqual(new ArrayShapeT([new ArrayShapeField('error', new LiteralT($error))]));
})->with([
    'beside a status read off the same case' => ['helperValueJson', 'Locked'],
    'with no status of its own' => ['helperNamed', 'Gone'],
    'under a chain restating the status' => ['helperValueChain', 'Gone'],
])->group('fixture');

it('publishes the one status every case chosen at run time maps to', function (string $method): void {
    // Expired and Revoked both answer 401, so whichever the request picks, 401 is what is sent.
    expect(caseStatusResponseStatus($method))->toEqual(new LiteralT(401));
})->with([
    'written inline' => ['agreeing'],
    'through a helper handed the case' => ['helperAgreeing'],
])->group('fixture');

it('claims no single status where the cases chosen at run time disagree', function (string $method): void {
    // Expired answers 401 and AlreadyRotated 409: either one alone would be a status half the requests are
    // never sent, so none is claimed, as a throw choosing between the same cases claims none.
    $status = caseStatusResponseStatus($method);

    expect($status)->not->toBeInstanceOf(LiteralT::class)
        ->and($status)->not->toBeInstanceOf(UnionT::class);
})->with([
    'written inline' => ['disagreeing'],
    'restated by a chain' => ['chainDisagreeing'],
    'through a helper handed the case' => ['helperDisagreeing'],
    // A backing value is no different: its type holds both codes, and it is still one case or the other.
    '$case->value into new JsonResponse()' => ['valueDisagreeing'],
    '$case->value into response()->json()' => ['valueDisagreeingJson'],
    '$case->value in a helper handed the case' => ['helperValueDisagreeing'],
])->group('fixture');

it('claims no status a chain in a helper restates off the case, rather than every code it may be', function (): void {
    // A chain's status is not bound at the call site, so the helper's own reading is all there is: the case
    // may be Gone or Locked, and publishing 410 and 423 would promise a 423 this call never sends.
    $status = caseStatusResponseStatus('helperValueChain');

    expect($status)->not->toBeInstanceOf(LiteralT::class)
        ->and($status)->not->toBeInstanceOf(UnionT::class);
})->group('fixture');

it('depends on the file the status was written in', function (string $method, string $file): void {
    // The number is decided in the enum, or beside the constant, not in the controller: editing the arm, the
    // backing value or the constant changes what this route publishes, so a warm build must not keep the old.
    expect(caseStatusResponseDependencies($method))->toContain($file);
})->with([
    'a method read inline' => ['json', 'TokenRefusal.php'],
    'a method read through a chain' => ['chain', 'TokenRefusal.php'],
    'a backing value read inline' => ['constructedValue', 'ExportFault.php'],
    'a backing value read in a helper handed the case' => ['helperValue', 'ExportFault.php'],
    'a method read in a helper handed the case' => ['helperCase', 'TokenRefusal.php'],
    'a class constant in response()->json()' => ['constant', 'ProbeStatuses.php'],
    'a class constant in a chain' => ['chainConstant', 'ProbeStatuses.php'],
])->group('fixture');
