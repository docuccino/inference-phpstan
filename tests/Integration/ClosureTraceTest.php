<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Real-engine proof of the closure-by-line trace: a closure located by start line hands its return
 * expressions to a {@see TraceVisitor} with a scope that can still type them. That is the path a closure
 * route's action takes, and an arrow function's scope is a lazy fiber scope which stops answering once
 * the analysis pass ends — so nothing may be deferred, and a stub engine can't prove any of it.
 *
 * The fixture app's `RateLimiter::for` registrations are the closures under test: an arrow function, a
 * full closure, and a conditional whose whole ternary must arrive as ONE return expression.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** The start line of the `RateLimiter::for('<name>', …)` closure in the fixture provider. */
function limiterLine(string $name): int
{
    $source = file_get_contents(FixtureRunner::path('app/Providers/AppServiceProvider.php'));
    $lines = $source === false ? [] : explode("\n", $source);
    foreach ($lines as $index => $line) {
        if (str_contains($line, "RateLimiter::for('".$name."'")) {
            return $index + 1;
        }
    }

    throw new RuntimeException("limiter '{$name}' not found in the fixture provider");
}

it('hands an arrow function its implicit return, typed', function (): void {
    $result = FixtureRunner::traceClosure('app/Providers/AppServiceProvider.php', limiterLine('api'));

    expect($result['returns'])->toHaveCount(1)
        ->and($result['returns'][0]['node'])->toBe('Expr_MethodCall')
        ->and($result['returns'][0]['type'])->toBe((new ClassT('Illuminate\\Cache\\RateLimiting\\Limit'))->canonicalKey());
})->group('fixture');

it('hands a full closure its return statement, typed', function (): void {
    $result = FixtureRunner::traceClosure('app/Providers/AppServiceProvider.php', limiterLine('uploads'));

    expect($result['returns'])->toHaveCount(1)
        ->and($result['returns'][0]['node'])->toBe('Expr_MethodCall')
        ->and($result['returns'][0]['type'])->toBe((new ClassT('Illuminate\\Cache\\RateLimiting\\Limit'))->canonicalKey());
})->group('fixture');

it('hands a conditional return over whole, not one branch at a time', function (): void {
    // The visitor sees the ternary itself, so nothing can mistake one arm of it for the closure's answer.
    $result = FixtureRunner::traceClosure('app/Providers/AppServiceProvider.php', limiterLine('dynamic'));

    expect($result['returns'])->toHaveCount(1)
        ->and($result['returns'][0]['node'])->toBe('Expr_Ternary')
        ->and($result['returns'][0]['type'])->not->toBe('');
})->group('fixture');
