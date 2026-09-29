<?php

declare(strict_types=1);

use App\Http\ClosureRoutes;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine proof that a closure is found at the line REFLECTION gives for it — which is how a closure
 * route's action and an exception-handler callback arrive — when its declaration begins earlier: an
 * attribute above it, or `static` on a line of its own. The lines come from `ReflectionFunction` over the
 * fixture's own closures, exactly as the router's closure is reflected, so nothing here restates the rule
 * the engine applies.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The line reflection reports for the fixture's closure under `$name`. */
function reflectedClosureLine(string $name): int
{
    require_once FixtureRunner::path('app/Http/ClosureRoutes.php');
    /** @var array<string, Closure> $closures */
    $closures = ClosureRoutes::all();

    return (int) (new ReflectionFunction($closures[$name]))->getStartLine();
}

/**
 * The `kind` each body answers with, read off the returns a trace hands over and off an analysis.
 *
 * @return array{traced: list<string>, analysed: list<string>}
 */
function closureKinds(string $name): array
{
    $line = reflectedClosureLine($name);
    $traced = FixtureRunner::traceClosure('app/Http/ClosureRoutes.php', $line)['returns'];
    $analysed = ActionAnalysis::fromArray(FixtureRunner::analyzeCallable('app/Http/ClosureRoutes.php', '', '', line: $line))->returns;

    $kind = static fn (string $key): string => preg_match('/"value":"(attributed|static|shadowed|neighbour|inner)"/', $key, $m) === 1 ? $m[1] : $key;

    return [
        'traced' => array_map(static fn (array $return): string => $kind((string) $return['type']), $traced),
        'analysed' => array_map(static fn ($site): string => $kind($site->type->canonicalKey()), $analysed),
    ];
}

it('finds a closure whose declaration begins before its keyword, and nothing else', function (string $name): void {
    expect(closureKinds($name))->toBe(['traced' => [$name], 'analysed' => [$name]]);
})->with([
    'an attribute on the line above' => ['attributed'],
    'static on the line above' => ['static'],
    // The parser starts `neighbour` on the line `shadowed`'s keyword is written on.
    'a keyword on the line a neighbour\'s attribute starts' => ['shadowed'],
    'the neighbour itself' => ['neighbour'],
])->group('fixture');

it('answers for neither of two closures whose keywords share a line', function (): void {
    // A line is all reflection gives, and it names both — the outer closure's answer is not the inner's.
    expect(closureKinds('nested'))->toBe(['traced' => [], 'analysed' => []]);
})->group('fixture');
