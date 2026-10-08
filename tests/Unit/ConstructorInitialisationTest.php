<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Metadata\ConstructorInitialisation;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\AssigningProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\InheritingProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\ReplacingProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\UnconstructedProbe;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;

/*
 * The half of the constructor reading that needs no analyser: which properties it answers for at all, and
 * that it never walks a file for the rest. Whether a real walk answers true or false for a body is the
 * fixture group's (`ConstructorInitialisationRealTest`). Here the walk is scripted empty, so a property it
 * does read comes back unanswered — no body seen is no claim made.
 */
it('reads the constructor only for a typed, undefaulted, unpromoted property its class declares or inherits', function (string $class, string $property, bool $walks): void {
    $adapter = new ScriptedRuntimeAdapter;
    $initialisation = new ConstructorInitialisation(new FileAnalyzer($adapter, new FileWalks($adapter)));
    $reflection = new ReflectionClass($class);

    expect($initialisation->of($reflection, $reflection->getProperty($property)))->toBeNull()
        ->and($adapter->totalPasses > 0)->toBe($walks);
})->with([
    'typed, assigned in the constructor' => [AssigningProbe::class, 'assigned', true],
    'typed, the subclass\'s own' => [ReplacingProbe::class, 'own', true],
    'defaulted' => [AssigningProbe::class, 'defaulted', false],
    'untyped' => [AssigningProbe::class, 'untyped', false],
    'promoted' => [AssigningProbe::class, 'promoted', false],
    // Answered by the parent's constructor, which the replacing one runs.
    'declared by a parent whose constructor the class replaces' => [ReplacingProbe::class, 'assigned', true],
    // An inherited constructor cannot be the one written for a property its subclass declares.
    'declared by a subclass of the constructor\'s class' => [InheritingProbe::class, 'own', false],
    'no constructor' => [UnconstructedProbe::class, 'title', false],
]);
