<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A response thrown inside an application's own subclass of `HttpResponseException` is read through the
 * subclass's constructors, so where it is written, and which files decide it, are the throw and those
 * constructors. What it documents is held by {@see RelayedResponseGoldenTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, ActionAnalysis> */
function relayedAnalyses(): array
{
    static $analyses = null;

    if ($analyses === null) {
        $analyses = [];
        $raw = FixtureRunner::analyzeMany(
            'app/Http/Controllers/RelayedResponseController.php',
            'App\\Http\\Controllers\\RelayedResponseController',
            ['builtItself', 'twoLevelsBuilt', 'factory'],
        );
        foreach ($raw as $method => $analysis) {
            /** @var array<string, mixed> $analysis */
            $analyses[(string) $method] = ActionAnalysis::fromArray($analysis);
        }
    }

    return $analyses;
}

/** The line of the first `throw` written in one action of the fixture controller. */
function relayedThrowLine(string $method): int
{
    $lines = explode("\n", (string) file_get_contents(FixtureRunner::path('app/Http/Controllers/RelayedResponseController.php')));
    $in = false;
    foreach ($lines as $index => $line) {
        $in = $in || str_contains($line, 'function '.$method.'(');
        if ($in && str_contains($line, 'throw ')) {
            return $index + 1;
        }
    }

    return 0;
}

it('places a response the class builds where it is thrown, not where the class builds it', function (): void {
    // The throw is what an author is pointed at; the constructor is the same for every throw of the class.
    $returns = relayedAnalyses()['builtItself']->returns;

    expect($returns)->toHaveCount(1)
        ->and($returns[0]->location->line)->toBe(relayedThrowLine('builtItself'))
        ->and($returns[0]->location->file)->toEndWith('RelayedResponseController.php');
})->group('fixture');

it('keys the route on every constructor between the class and the framework, and on the factory', function (string $method, array $files): void {
    // A warm build must rebuild once any of them changes what the throw carries.
    $dependencies = array_map(static fn (string $file): string => basename($file), relayedAnalyses()[$method]->dependencyFiles);

    expect($dependencies)->toContain(...$files);
})->with([
    'two levels below the framework' => ['twoLevelsBuilt', ['QuotaExceededException.php', 'RelayedResponseException.php']],
    'a static factory' => ['factory', ['ExportRefusedException.php']],
])->group('fixture');
