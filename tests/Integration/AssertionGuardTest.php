<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A throw with no status is a response a request can get only where something about the request reaches
 * it. Where only the application's own guarantees failing does — a class PHP defines as a defect in the
 * program, or a throw past a failed type test — it is an assertion, and no request a correctly wired
 * application receives gets the 500 it would fall back to. Which of the two a throw is never depends on how
 * it is written: a literal `throw`, a helper returning `never` or `void`, `throw_if()` and `throw_unless()`
 * and a closure each give one answer for one guard.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> every AssertionGuardController action, by name */
function assertionGuardActions(): array
{
    static $analyses = null;

    if ($analyses === null) {
        $file = (string) file_get_contents(FixtureRunner::path('app/Http/Controllers/AssertionGuardController.php'));
        preg_match_all('/public function (\w+)\(/', $file, $found);

        // A scan that matched nothing must fail rather than pass forever.
        expect(count($found[1]))->toBeGreaterThan(20);

        /** @var array<string, array<string, mixed>> $analyses */
        $analyses = FixtureRunner::analyzeMany(
            'app/Http/Controllers/AssertionGuardController.php',
            'App\\Http\\Controllers\\AssertionGuardController',
            $found[1],
        );
    }

    return $analyses;
}

/**
 * Every throw one action hands the document, as `Short@status disposition`.
 *
 * @return list<string>
 */
function assertionGuardThrows(string $method): array
{
    $out = [];
    /** @var list<array<string, mixed>> $throws */
    $throws = assertionGuardActions()[$method]['throws'];
    foreach ($throws as $throw) {
        $class = explode('\\', (string) $throw['exceptionFqcn']);
        $out[] = end($class).'@'.($throw['httpStatusHint'] ?? 'null').' '.(string) $throw['disposition'];
    }
    sort($out);

    return $out;
}

it('files a throw with no status as an assertion where only the application\'s own guarantees reach it', function (string $method, array $expected): void {
    expect(assertionGuardThrows($method))->toBe($expected);
})->with([
    // A list builder's subclass must name its row class; a helper refusing to go on without one is reached
    // only where the class is missing, which no request decides. `LogicException` says so, as does the `??`.
    'a never helper behind ??' => ['listWithNeverHelper', ['LogicException@500 assertion']],
    'its void twin behind an if' => ['listWithVoidHelper', ['LogicException@500 assertion']],
    'a never helper throwing a RuntimeException behind ??' => ['listWithRuntimeHelper', ['RuntimeException@500 assertion']],
    // The user the route's middleware put there, asserted every way it is written.
    'throw_unless() on an instanceof' => ['unlessUser', ['RuntimeException@500 assertion']],
    'throw_if() on === null' => ['ifNoUser', ['RuntimeException@500 assertion']],
    'a literal throw behind ??' => ['coalesceUser', ['RuntimeException@500 assertion']],
    'a literal throw in an if, after a log line' => ['ifNotUser', ['RuntimeException@500 assertion']],
    'a literal throw after an early return' => ['earlyReturnUser', ['RuntimeException@500 assertion']],
    'a literal throw behind ||' => ['orThrowUser', ['RuntimeException@500 assertion']],
    'a void helper called behind the test' => ['voidHelperBehindGuard', ['RuntimeException@500 assertion']],
    'its never twin' => ['neverHelperBehindGuard', ['RuntimeException@500 assertion']],
    'a closure handed to a call behind ??' => ['closureBehindGuard', ['RuntimeException@500 assertion']],
    'an arrow function testing its own value' => ['arrowWithOwnGuard', ['RuntimeException@500 assertion']],
    // PHP's defect family, whatever guards it.
    'a LogicException behind a test of a uuid' => ['unlessUuid', ['LogicException@500 assertion']],
    'an InvalidArgumentException behind a flag' => ['logicBehindValue', ['InvalidArgumentException@500 assertion']],
    'the application\'s own LogicException, behind a flag' => ['misconfiguredListing', ['ListingMisconfiguredException@500 assertion']],
    // The engine reads no status off a `render()`; the adapter does, and publishes the assertion at it.
    'an InvalidArgumentException the application renders' => ['radiusOutOfRange', ['UnsupportedRadiusException@500 assertion']],
])->group('fixture');

it('publishes a throw with no status a request can reach', function (string $method, array $expected): void {
    expect(assertionGuardThrows($method))->toBe($expected);
})->with([
    // `Str::isUuid()` reads the value, which a request decides: one of the two ways the guard fails is not a
    // type test, so the throw is reachable.
    'a type test joined to a test of the value' => ['unlessUuidRuntime', ['RuntimeException@500 signal']],
    'a test of a value' => ['valueGuard', ['RuntimeException@500 signal']],
    // A unique violation on another index than the one handled is re-thrown, the handled one refused.
    'a re-thrown violation of another index' => ['rethrowOtherIndex', ['HttpException@422 signal', 'UniqueConstraintViolationException@500 signal']],
])->group('fixture');

it('publishes one class thrown as an assertion at one site and as an error at another, in either order', function (string $method): void {
    // A response one site really sends is not unsent by another site asserting the same class.
    expect(assertionGuardThrows($method))->toBe(['RuntimeException@500 signal']);
})->with(['sameClassTwoSites', 'sameClassTwoSitesReversed'])->group('fixture');

it('keeps the status an exception behind a type test carries', function (string $method, array $expected): void {
    expect(assertionGuardThrows($method))->toBe($expected);
})->with([
    // The engine reads no status off this class — the framework renders it at 401 — so it is filed as an
    // assertion and handed on: the adapter publishes an assertion wherever it renders at a status of its own.
    'an AuthenticationException behind an instanceof' => ['unlessUserAuthenticated', ['AuthenticationException@500 assertion']],
    'an AuthenticationException behind a flag' => ['ifSystemAuthenticated', ['AuthenticationException@500 signal']],
    'an HttpException behind an instanceof' => ['unlessUserHttp', ['HttpException@403 signal']],
])->group('fixture');

it('depends on the ancestry that makes a class an assertion', function (): void {
    /** @var list<string> $dependencies */
    $dependencies = assertionGuardActions()['misconfiguredListing']['dependencyFiles'];

    // Re-parenting the class onto `RuntimeException` turns the assertion into a published 500, so a warm build
    // has to rebuild the route when its file changes.
    expect(array_map('basename', $dependencies))->toContain('ListingMisconfiguredException.php');
})->group('fixture');
