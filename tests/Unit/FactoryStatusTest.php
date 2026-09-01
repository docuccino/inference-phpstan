<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeBranchingFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeIndirectFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeInheritsFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeMovedStatus;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeNamedFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeOverridingFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbePinsWithFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbePlain;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeScanFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeSpreadFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeTraitFactory;
use Docuccino\Inference\PhpStan\Tests\Support\ParsedBodies;
use Docuccino\Inference\PhpStan\Tests\Support\RecordingBodies;
use Docuccino\Inference\PhpStan\Throwing\FactoryStatus;
use Docuccino\Inference\PhpStan\Throwing\HttpExceptionStatus;

/**
 * The one hop from a `throw X::y()` into the factory it names, over REAL exception classes: the hierarchy,
 * the visibility and the constructor defaults are reflection over the probes, and only the bodies and the
 * fold come from a source. That the analyser hands over the same bodies is the fixture group's job
 * ({@see ThrowCasesTest}); everything the hop DECIDES is decided here.
 */
function factoryStatuses(bool $projectSeesTheFile = true): FactoryStatus
{
    $bodies = new ParsedBodies;

    $filter = factoryProjectFilter($projectSeesTheFile);

    return new FactoryStatus(new HttpExceptionStatus($bodies, $filter), $bodies, $filter);
}

function factoryProjectFilter(bool $sees): ProjectFilter
{
    return new ProjectFilter($sees ? ['/'] : [], static fn (string $file): string => $file);
}

it('reads the status the factory named at the throw builds with', function (string $fqcn, string $method, ?int $status): void {
    expect(factoryStatuses()->forFactory($fqcn, $method)['status'])->toBe($status);
})->with([
    'a factory that builds then decorates' => [ProbeScanFactory::class, 'detected', 422],
    // Named rather than counted, which a reader that only counts positions would see no status in at all.
    'a factory naming its arguments' => [ProbeNamedFactory::class, 'conflicting', 409],
    // The class states no ONE status because each factory chooses, which is exactly why the hop exists: the
    // two factories are two statuses, and the throw says which one it is.
    'a factory passing its own status' => [ProbeOverridingFactory::class, 'conflicting', 409],
    'a sibling factory leaving the slot to the default' => [ProbeOverridingFactory::class, 'rejected', 422],
    // …and every negative, each of which would be a status the factory does not pass.
    'a factory that builds the class two ways' => [ProbeBranchingFactory::class, 'forRetry', null],
    'a factory spreading arguments nothing can read' => [ProbeSpreadFactory::class, 'replaying', null],
    'a factory that builds through another' => [ProbeIndirectFactory::class, 'locked', null],
    // The constructor moves the status it was handed, so what the factory puts in the slot is not what the
    // response is — and reading the default it left empty would publish a 422 for a 400.
    'a factory of a class whose constructor moves the status' => [ProbeMovedStatus::class, 'none', null],
    'a factory the class inherits rather than declares' => [ProbeInheritsFactory::class, 'unavailable', null],
    'a factory a trait writes, in another file' => [ProbeTraitFactory::class, 'conflicting', null],
    'an instance method, which no factory throw names' => [ProbeScanFactory::class, 'detail', null],
    'a method the class does not have' => [ProbeScanFactory::class, 'nope', null],
    'a class that is not an HttpException' => [ProbePlain::class, 'make', null],
    'a name no class answers to' => ['App\\Nope\\NoSuchException', 'make', null],
]);

it('will not read a factory outside the project', function (): void {
    // The same factory the row above folds to 422, behind a filter that calls its file vendor: the gate is
    // what changes the answer, and nothing else.
    expect(factoryStatuses(false)->forFactory(ProbeScanFactory::class, 'detected')['status'])->toBeNull();
});

it('records the factory file, read or refused', function (bool $projectSeesTheFile): void {
    // Fragment-cache soundness: the factory now decides what the route publishes, so editing it — including
    // editing it into a shape this hop CAN read — has to rebuild every route that throws through it.
    $read = factoryStatuses($projectSeesTheFile)->forFactory(ProbeScanFactory::class, 'detected');

    expect(array_map(static fn (string $file): string => basename($file), $read['files']))
        ->toBe(['ProbeScanFactory.php']);
})->with([true, false]);

it('never reads the factory of a class that pins a LITERAL', function (): void {
    $bodies = new RecordingBodies(new ParsedBodies);
    $filter = factoryProjectFilter(true);
    $factories = new FactoryStatus(new HttpExceptionStatus($bodies, $filter), $bodies, $filter);

    // `new self(409)` is the same construction the rows above fold to 409 on a class that pins nothing. Here
    // the class pins 410 with a literal, so it forwards no slot at all: no file is recorded and no
    // construction is ever folded, which is the guard rather than a claim about it.
    expect($factories->forFactory(ProbePinsWithFactory::class, 'gone'))->toBe(['status' => null, 'files' => []])
        ->and($bodies->folded)->not->toContain('construction')
        ->and($bodies->folded)->toContain('parent-call');
});

it('DOES read the factory of a class pinned by its constructor default, and agrees with it', function (): void {
    // The other pin spelling, which the guard above says nothing about: a private constructor's default
    // pins 422 AND still names slot 1, so this read runs. The file is recorded — the observable that it ran
    // at all — and the answer is the same 422, because a factory leaving the slot empty passes that default.
    $read = factoryStatuses()->forFactory(ProbeFactory::class, 'none');

    expect($read['status'])->toBe(422)
        ->and(array_map(static fn (string $file): string => basename($file), $read['files']))
        ->toBe(['ProbeFactory.php']);
});

it('reads one factory once, however many routes throw it', function (): void {
    $bodies = new RecordingBodies(new ParsedBodies);
    $filter = factoryProjectFilter(true);
    $factories = new FactoryStatus(new HttpExceptionStatus($bodies, $filter), $bodies, $filter);

    $first = $factories->forFactory(ProbeScanFactory::class, 'detected');
    $folds = count($bodies->folded);

    expect($factories->forFactory(ProbeScanFactory::class, 'detected'))->toBe($first)
        ->and($first['status'])->toBe(422)
        ->and($bodies->folded)->toHaveCount($folds);
});
