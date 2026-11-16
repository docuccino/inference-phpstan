<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\AccessorKind;
use Docuccino\Inference\PhpStan\Analysis\ParentArgument;
use Docuccino\Inference\PhpStan\Analysis\ResponseShapeRefiner;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbePinned;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeBranchSetResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeConflictProblem;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeDataResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeGoneResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeHeaderBagResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeMovedResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeNormalisedResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbePlainJsonResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeProblemResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeRetryResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeRewrittenResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeSetBeforeParentResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeSetForwardedResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeSetStatusResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeTwiceSetResponse;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses\ProbeUnavailableResponse;
use Docuccino\Inference\PhpStan\Tests\Support\ParsedBodies;
use Docuccino\Inference\PhpStan\Throwing\SubclassStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/**
 * The status a response class the application derives from the framework's hands it, read over REAL
 * classes: the same walk an `HttpException` subclass's status is read by ({@see SubclassStatusTest}), rooted
 * at each framework response's constructor and its `status` parameter. What the analyser folds and the
 * scope a body is typed in are the fixture group's ({@see ResponseSubclassTest}); what the read decides is
 * decided here.
 */
function responseStatuses(bool $projectSeesTheFile = true): SubclassStatus
{
    return SubclassStatus::ofResponses(new ParsedBodies, responseFilter($projectSeesTheFile), ResponseShapeRefiner::roots());
}

function responseFilter(bool $sees): ProjectFilter
{
    return new ProjectFilter($sees ? ['/'] : [], static fn (string $file): string => $file);
}

/**
 * Every response probe: `[status every instance is sent with, the class's constructor slot a construction
 * fills it from, the read applied to that slot's argument, what lands in the body slot, what lands in the
 * headers slot]`. A landing is the expression printed (`expr …`), the slot a construction fills
 * (`parameter N`), or null for nothing read.
 *
 * @return array<string, array{0: class-string, 1: int|null, 2: int|null, 3: string|null, 4: string|null, 5: string|null}>
 */
function responseProbeRows(): array
{
    return [
        // The framework's status argument is the class's, so the class IS that status.
        'a literal reaching the parent' => [ProbeGoneResponse::class, 410, null, null, "expr ''", null],
        'a constant reaching the parent, below a root inheriting Symfony\'s constructor' => [ProbeMovedResponse::class, 301, null, null, null, null],
        // A public constructor's default is what a construction passing nothing gets; one passing a status
        // gets that, so the default is no status of the class's, and the slot is where to read it.
        'a defaulted status a caller may pass' => [ProbeRetryResponse::class, null, 1, null, 'parameter 0', null],
        // An empty slot passes the parent's default — the application's parent's, or the framework's.
        'a slot left empty below a parent defaulting it' => [ProbeUnavailableResponse::class, 503, null, null, "expr ['retry' => false]", null],
        'a slot left empty below the framework' => [ProbeDataResponse::class, 200, null, null, 'parameter 0', null],
        // No constructor of its own: the framework's runs, so its slots are the class's.
        'no constructor of its own' => [ProbePlainJsonResponse::class, null, 1, null, 'parameter 0', 'parameter 2'],
        // A status set once the parent's constructor has run is the one sent — fixed, or the caller's.
        'a status set after the parent\'s' => [ProbeSetStatusResponse::class, 410, null, null, 'parameter 0', null],
        'a caller\'s status set after the parent\'s' => [ProbeSetForwardedResponse::class, null, 0, null, "expr ''", null],
        // …and every set this cannot place as the one sent states nothing: the parent's replaces one set
        // before it, a branch may not run, and of two the read would be guessing which is last.
        'a status set before the parent\'s' => [ProbeSetBeforeParentResponse::class, null, null, null, "expr ''", null],
        'a status set on one branch' => [ProbeBranchSetResponse::class, null, null, null, 'parameter 0', null],
        'a status set twice' => [ProbeTwiceSetResponse::class, null, null, null, "expr ''", null],
        // A body replaced once the parent's has run is not the one handed to it.
        'a body replaced after the parent\'s' => [ProbeRewrittenResponse::class, 202, null, null, null, null],
        // A parameter written before it is handed on is not the caller's: what lands is read where it is.
        'a body parameter rewritten before it is handed on' => [ProbeNormalisedResponse::class, 200, null, null, 'expr $data', null],
        // The media type a parent fixes in the headers it hands the framework's is every subclass's too.
        'headers a problem fixes' => [ProbeProblemResponse::class, null, 1, null, 'parameter 0', "expr ['Content-Type' => 'application/problem+json']"],
        'headers inherited from the problem it extends' => [ProbeConflictProblem::class, 409, null, null, "expr ['title' => 'Conflict']", "expr ['Content-Type' => 'application/problem+json']"],
        // …unless the constructor rewrites them once the parent's has run.
        'headers rewritten through the bag' => [ProbeHeaderBagResponse::class, 200, null, null, 'expr []', null],
    ];
}

it('reads the status a derived response hands the framework\'s', function (string $fqcn, ?int $pinned, ?int $parameter, ?string $read, ?string $body, ?string $headers): void {
    $statuses = responseStatuses();
    $through = $statuses->statusRead($fqcn);

    expect($statuses->pinned($fqcn))->toBe($pinned)
        ->and($statuses->statusParameter($fqcn))->toBe($parameter)
        ->and($through === null ? null : ($through->kind === AccessorKind::Method ? $through->method.'()' : '->value'))->toBe($read);
})->with(responseProbeRows());

it('reads the body and headers a derived response hands the framework\'s', function (string $fqcn, ?int $pinned, ?int $parameter, ?string $read, ?string $body, ?string $headers): void {
    $root = responseStatuses()->root($fqcn) ?? '';
    $arguments = new ParentArgument(new ParsedBodies, responseFilter(true));
    $printed = static fn (?array $landing): ?string => match (true) {
        $landing === null => null,
        isset($landing['expr']) => 'expr '.(new Standard)->prettyPrintExpr($landing['expr']),
        default => 'parameter '.$landing['parameter'],
    };

    expect($printed($arguments->body($fqcn, $root)))->toBe($body)
        ->and($printed($arguments->headers($fqcn, $root)))->toBe($headers);
})->with(array_filter(responseProbeRows(), static fn (array $row): bool => $row[0] !== ProbeMovedResponse::class));

it('has a row for every response probe the fixtures directory ships', function (): void {
    // A dataset only proves the rows it lists; a probe added and named nowhere proves nothing.
    $probes = array_map(
        static fn (string $file): string => 'Docuccino\\Inference\\PhpStan\\Tests\\Support\\Fixtures\\Responses\\'.basename($file, '.php'),
        glob(dirname(__DIR__).'/Support/Fixtures/Responses/*.php') ?: [],
    );
    $rows = array_map(static fn (array $row): string => $row[0], responseProbeRows());

    expect(count($probes))->toBeGreaterThanOrEqual(10)
        ->and(array_values(array_diff($probes, $rows)))->toBe([]);
});

it('answers for the framework\'s responses and what derives from them, at the root nearest the class', function (string $fqcn, ?string $root): void {
    expect(responseStatuses()->root($fqcn))->toBe($root)
        ->and(responseStatuses()->governs($fqcn))->toBe($root !== null);
})->with([
    'a root itself' => [JsonResponse::class, JsonResponse::class],
    // Illuminate's response extends Symfony's, and the nearer root is the one whose constructor runs.
    'a plain response\'s subclass' => [ProbeGoneResponse::class, Response::class],
    'a redirect\'s subclass' => [ProbeMovedResponse::class, RedirectResponse::class],
    'two classes below the framework' => [ProbeUnavailableResponse::class, JsonResponse::class],
    'an exception' => [ProbePinned::class, null],
    'a name no class answers to' => ['App\\Nope\\NoSuchResponse', null],
]);

it('sends each root with the default its installed constructor names', function (string $root, ?int $default): void {
    expect(responseStatuses()->rootDefault($root))->toBe($default);
})->with([
    'a JSON response' => [JsonResponse::class, 200],
    'a redirect' => [RedirectResponse::class, 302],
    'a class that is no root' => [ProbeGoneResponse::class, null],
]);

it('will not read a derived response outside the project', function (): void {
    // The same gate an exception class is behind ({@see SubclassStatus}): a file the build reads nothing
    // out of states nothing. The hierarchy is still reflection, so a class adding no constructor answers.
    $gated = responseStatuses(false);
    $bodies = new ParentArgument(new ParsedBodies, responseFilter(false));

    expect($gated->pinned(ProbeGoneResponse::class))->toBeNull()
        ->and($gated->statusParameter(ProbePlainJsonResponse::class))->toBe(1)
        ->and($bodies->body(ProbeGoneResponse::class, Response::class))->toBeNull()
        ->and($bodies->body(ProbePlainJsonResponse::class, JsonResponse::class))->toBe(['parameter' => 0]);
});

it('reads one class\'s body once', function (): void {
    $bodies = new ParentArgument(new ParsedBodies, responseFilter(true));
    $first = $bodies->body(ProbeGoneResponse::class, Response::class);

    expect($first['expr'] ?? null)->toBeInstanceOf(Node\Scalar\String_::class)
        ->and($bodies->body(ProbeGoneResponse::class, Response::class))->toBe($first)
        ->and($bodies->body('App\\Nope\\NoSuchResponse', Response::class))->toBeNull();
});
