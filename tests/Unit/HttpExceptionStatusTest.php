<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeBranchingFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeConstructedDefault;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeInherited;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeNoConstructor;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeOutOfRangeConstantPin;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeOutOfRangeDefault;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbePinned;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbePlain;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeScanFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeSideEffect;
use Docuccino\Inference\PhpStan\Tests\Support\HttpProbeRows;
use Docuccino\Inference\PhpStan\Tests\Support\ParsedBodies;
use Docuccino\Inference\PhpStan\Tests\Support\RecordingBodies;
use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use Docuccino\Inference\PhpStan\Throwing\HttpExceptionStatus;
use PhpParser\Node;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The status read over REAL exception classes — the hierarchy, the visibility and the parse are all real,
 * and only the bodies, the fold and the parameter defaults come from a source. That the analyser hands over
 * the same bodies and folds the same constants is the fixture group's job ({@see ThrowStatusTest});
 * everything the read DECIDES is decided here.
 */
function httpStatuses(bool $projectSeesTheFile = true): HttpExceptionStatus
{
    return new HttpExceptionStatus(new ParsedBodies, httpProjectFilter($projectSeesTheFile));
}

function httpProjectFilter(bool $sees): ProjectFilter
{
    return new ProjectFilter($sees ? ['/'] : [], static fn (string $file): string => $file);
}

it('reads what a class states about its status', function (string $fqcn, ?int $pinned, ?int $parameter, ?int $agreed): void {
    // One row per class, all three answers on it: they divide the same domain, and two datasets covered
    // their own halves and left seven probes with nothing saying which slot they forward.
    $statuses = httpStatuses();

    expect($statuses->pinned($fqcn))->toBe($pinned)
        ->and($statuses->statusParameter($fqcn))->toBe($parameter)
        ->and($statuses->agreed($fqcn)['status'])->toBe($agreed);
})->with([
    ...HttpProbeRows::statuses(),
    // No class below `HttpException` adds a constructor, so its own runs and argument 0 IS the status.
    // It builds nothing of itself either, so there is no construction of it to agree on.
    'HttpException itself' => [HttpException::class, null, 0, null],
    // Symfony's own subclasses pin like an application's — but only where the file is one the build reads;
    // "will not read a class outside the project" below is the same class behind the gate the engine
    // really applies, and that gate is why this answer never reaches a real document.
    'a vendor subclass pinning its own' => [NotFoundHttpException::class, 404, null, null],
    'a name no class answers to' => ['App\\Nope\\NoSuchException', null, null, null],
]);

it('reads one class\'s own constructions once, however many routes throw it', function (): void {
    // The memo holds its NULLS too, which a return value cannot show: a class that agrees on nothing is
    // the commonest answer, and re-reading it per route would re-parse the same body for every throw.
    $bodies = new RecordingBodies(new ParsedBodies);
    $statuses = new HttpExceptionStatus($bodies, httpProjectFilter(true));

    $agreed = $statuses->agreed(ProbeBranchingFactory::class);
    $folds = count($bodies->folded);

    expect($agreed['status'])->toBeNull()
        ->and($folds)->toBeGreaterThan(0)
        ->and($statuses->agreed(ProbeBranchingFactory::class))->toBe($agreed)
        ->and($bodies->folded)->toHaveCount($folds);
});

it('never lets the three answers contradict each other', function (): void {
    // Covering is not agreeing: the rows above prove each answer on its own and say nothing about whether
    // they can stand together. Both rules are stated from what the answers MEAN rather than asked of the
    // code — a class stating one status for every instance cannot have its own constructions building
    // another, and where no slot is forwarded there is nowhere for a construction's status to be read.
    $contradictions = [];
    $withBoth = 0;
    $withoutSlot = 0;

    foreach (HttpProbeRows::statuses() as $label => [$fqcn, $pinned, $parameter, $agreed]) {
        if ($pinned !== null && $agreed !== null) {
            $withBoth++;
            if ($pinned !== $agreed) {
                $contradictions[] = $label.': pins '.$pinned.' and builds '.$agreed;
            }
        }

        if ($parameter === null) {
            $withoutSlot++;
            if ($agreed !== null) {
                $contradictions[] = $label.': forwards no slot and still agrees on '.$agreed;
            }
        }
    }

    expect($contradictions)->toBe([])
        // …and a floor, so a table that stopped carrying either kind passes nothing silently.
        ->and($withBoth)->toBeGreaterThanOrEqual(3)
        ->and($withoutSlot)->toBeGreaterThanOrEqual(8);
});

it('has a row for every probe the fixtures directory ships', function (): void {
    // A dataset only proves the rows it lists, and this directory is a hand-maintained full set: a probe
    // added and named nowhere would leave the whole suite green while proving nothing about it.
    $probes = HttpProbeRows::probeClasses();

    expect($probes)->toHaveCount(count(array_unique($probes)))
        ->and(count($probes))->toBeGreaterThanOrEqual(20)
        ->and(array_values(array_diff($probes, HttpProbeRows::coveredClasses())))->toBe([]);
});

it('will not read a class outside the project', function (): void {
    // The gate, and nothing else, is what changes the answer. It is not a policy: PHPStan strips an
    // unprimed file's bodies, so the analyser reads exactly nothing out of a vendor exception while paying
    // to prime it — the fixture group measures that, and this pins the decision the gate encodes.
    $gated = httpStatuses(false);

    expect($gated->pinned(NotFoundHttpException::class))->toBeNull()
        ->and($gated->pinned(ProbePinned::class))->toBeNull()
        // The class's own constructions are behind the same gate: a body PHPStan strips reads as a class
        // that builds itself nowhere, which would answer the constructor's default for a class stating none.
        ->and($gated->agreed(ProbeScanFactory::class)['status'])->toBeNull()
        ->and($gated->agreed(ProbeFactory::class)['status'])->toBeNull()
        // The hierarchy is still reflection, so a class adding no constructor still names its slot.
        ->and($gated->statusParameter(ProbeNoConstructor::class))->toBe(0);
});

it('reports the constructor slot a construction would fill, and the default it would take', function (string $fqcn, int $slot, array $names, ?int $default): void {
    expect(httpStatuses()->constructorSlot($fqcn, $slot))->toBe(['names' => $names, 'default' => $default]);
})->with([
    // A class of its own: the slot the factories leave empty carries the value they all take.
    'a defaulted slot' => [ProbeFactory::class, 1, ['fields', 'statusCode'], 422],
    'the slot before it, which has no default' => [ProbeFactory::class, 0, ['fields', 'statusCode'], null],
    // No constructor of its own, so the framework's is what a `new` binds to — status first, no default.
    'the inherited constructor' => [ProbeNoConstructor::class, 0, ['statusCode', 'message', 'previous', 'headers', 'code'], null],
    // A default that is not an integer is not a status, however present it is.
    'a slot defaulting to a string' => [ProbeNoConstructor::class, 1, ['statusCode', 'message', 'previous', 'headers', 'code'], null],
    // Nor is one outside the range a response key can take.
    'a default outside the range a status can take' => [ProbeOutOfRangeDefault::class, 0, ['statusCode'], null],
    'a slot past the end of the signature' => [ProbePinned::class, 4, [], null],
    'a name no class answers to' => ['App\\Nope\\NoSuchException', 0, [], null],
]);

it('never executes the code it is reading', function (): void {
    // `ReflectionParameter::getDefaultValue()` EVALUATES the initialiser, and PHP has allowed `new` in one
    // since 8.1 — so asking reflection for a default runs an analysed application's constructor inside the
    // generator. The count is the guard: reading this class's defaults may not move it.
    $before = ProbeSideEffect::$constructed;
    $statuses = httpStatuses();

    expect($statuses->constructorSlot(ProbeConstructedDefault::class, 0))
        ->toBe(['names' => ['marker', 'statusCode'], 'default' => null])
        ->and($statuses->pinned(ProbeConstructedDefault::class))->toBe(422)
        ->and(ProbeSideEffect::$constructed)->toBe($before);
});

it('records every file in the hierarchy, not only the one that declares the constructor today', function (): void {
    // Fragment-cache soundness: adding a constructor to the base changes the answer, so the base's file has
    // to be able to invalidate the routes that throw the subclass.
    $files = httpStatuses()->filesFor(ProbeInherited::class);
    $names = array_map(static fn (string $file): string => basename($file), $files);

    expect($names)->toContain('ProbeInherited.php')
        ->and($names)->toContain('ProbeBase.php')
        ->and($names)->toContain('HttpException.php')
        ->and(httpStatuses()->filesFor(ProbePlain::class))->toBe([]);
});

it('records a declaration a fold READ even where the fold refused it', function (): void {
    // Fragment-cache soundness on a DECLINE. `parent::__construct(ProbeConstantHolder::OUT_OF_RANGE, …)`
    // reads its status out of another file and 99 is no response key, so the class states nothing — and
    // that other file is still what decided: edit the constant to 415 and a cold build publishes 415 while
    // a warm one, keyed without the file, goes on publishing no status at all. What the read went through
    // to reach its answer is a dependency whether or not the answer was a status.
    $read = httpStatuses();
    $names = array_map(static fn (string $file): string => basename($file), $read->filesFor(ProbeOutOfRangeConstantPin::class));

    expect($read->pinned(ProbeOutOfRangeConstantPin::class))->toBeNull()
        ->and($names)->toContain('ProbeConstantHolder.php')
        ->and($names)->toContain('ProbeOutOfRangeConstantPin.php');
});

it('states nothing about a class whose body it cannot read', function (): void {
    // A source with no bodies is what an unparsable or stripped file looks like from here. The class still
    // reflects — it is an HttpException, and it declares a constructor — so this is the branch where the
    // answer has to be "unknown" rather than the default the constructor happens to carry.
    $blind = new class implements ClassBodies
    {
        public function methods(string $file, string $class): array
        {
            return [];
        }

        public function foldInt(string $file, Node\Expr $expr, Node\Expr\New_|Node\Expr\StaticCall $at): ?int
        {
            return null;
        }

        public function intDefault(string $file, string $class, string $method, int $index): ?int
        {
            return null;
        }
    };

    $statuses = new HttpExceptionStatus($blind, httpProjectFilter(true));

    expect($statuses->pinned(ProbePinned::class))->toBeNull()
        ->and($statuses->pinned(ProbeFactory::class))->toBeNull()
        ->and($statuses->agreed(ProbeScanFactory::class)['status'])->toBeNull()
        ->and($statuses->statusParameter(ProbePinned::class))->toBeNull()
        // The hierarchy is still reflection, so a class adding no constructor still answers.
        ->and($statuses->statusParameter(ProbeNoConstructor::class))->toBe(0);
});

it('answers an HttpException subclass and nothing else', function (string $fqcn, bool $is): void {
    expect(httpStatuses()->isHttpException($fqcn))->toBe($is);
})->with([
    'a subclass' => [ProbePinned::class, true],
    'the class itself' => [HttpException::class, true],
    'a plain exception' => [ProbePlain::class, false],
    'a name no class answers to' => ['App\\Nope\\NoSuchException', false],
]);
