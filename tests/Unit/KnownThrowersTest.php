<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Inference\PhpStan\Throwing\KnownThrower;
use Docuccino\Inference\PhpStan\Throwing\KnownThrowers;

/**
 * The status table is single-sourced on the registry: `statusForExceptionFqcn()`
 * (consulted by the throw analyzer's layer-1 enrichment) and `forMethod()`
 * (layer-2 rescue) draw from the SAME registered throwers, so a user's
 * `withMethod()` extension enriches both layers rather than only the rescue path.
 */
it('exposes the default throwers as an exception-FQCN status source', function (): void {
    $registry = KnownThrowers::default();

    expect($registry->statusForExceptionFqcn(KnownThrowers::VALIDATION_EXCEPTION))->toBe(422)
        ->and($registry->statusForExceptionFqcn(KnownThrowers::AUTHORIZATION_EXCEPTION))->toBe(403)
        ->and($registry->statusForExceptionFqcn(KnownThrowers::MODEL_NOT_FOUND_EXCEPTION))->toBe(404)
        // abort's HttpException folds its status from a call argument, so it has
        // no fixed status and must NOT appear as an enrichable exception FQCN.
        ->and($registry->statusForExceptionFqcn(KnownThrowers::HTTP_EXCEPTION))->toBeNull()
        ->and($registry->statusForExceptionFqcn('App\\Exceptions\\Nope'))->toBeNull();
});

it('registers every default function thrower with its exception and status source', function (
    string $name,
    string $exceptionFqcn,
    ?int $fixedStatus,
    ?int $statusArgIndex,
): void {
    $thrower = KnownThrowers::default()->forFunction($name);

    expect($thrower)->not->toBeNull()
        ->and($thrower->exceptionFqcn)->toBe($exceptionFqcn)
        ->and($thrower->fixedStatus)->toBe($fixedStatus)
        ->and($thrower->statusArgIndex)->toBe($statusArgIndex)
        ->and($thrower->foldsStatusFromArgument())->toBe($statusArgIndex !== null);
})->with([
    // abort($status) folds the status from arg 0; abort_if/abort_unless($cond, $status) from arg 1.
    'abort' => ['abort', KnownThrowers::HTTP_EXCEPTION, null, 0],
    'abort_if' => ['abort_if', KnownThrowers::HTTP_EXCEPTION, null, 1],
    'abort_unless' => ['abort_unless', KnownThrowers::HTTP_EXCEPTION, null, 1],
]);

it('registers every default method thrower with its exception and fixed status', function (
    string $name,
    string $exceptionFqcn,
    int $status,
): void {
    $registry = KnownThrowers::default();
    $thrower = $registry->forMethod($name);

    expect($thrower)->not->toBeNull()
        ->and($thrower->exceptionFqcn)->toBe($exceptionFqcn)
        ->and($thrower->fixedStatus)->toBe($status)
        ->and($thrower->foldsStatusFromArgument())->toBeFalse()
        // Every fixed-status method thrower is also reachable through the FQCN status source.
        ->and($registry->statusForExceptionFqcn($exceptionFqcn))->toBe($status);
})->with([
    'authorize' => ['authorize', KnownThrowers::AUTHORIZATION_EXCEPTION, 403],
    'authorizeForUser' => ['authorizeForUser', KnownThrowers::AUTHORIZATION_EXCEPTION, 403],
    'findOrFail' => ['findOrFail', KnownThrowers::MODEL_NOT_FOUND_EXCEPTION, 404],
    'firstOrFail' => ['firstOrFail', KnownThrowers::MODEL_NOT_FOUND_EXCEPTION, 404],
    'sole' => ['sole', KnownThrowers::MODEL_NOT_FOUND_EXCEPTION, 404],
    'validate' => ['validate', KnownThrowers::VALIDATION_EXCEPTION, 422],
]);

it('returns null for an unregistered function or method name (unknown-entry contract)', function (): void {
    $registry = KnownThrowers::default();

    expect($registry->forFunction('dd'))->toBeNull()
        ->and($registry->forFunction('authorize'))->toBeNull()   // a method name is not a function name
        ->and($registry->forMethod('abort'))->toBeNull()         // a function name is not a method name
        ->and($registry->forMethod('whereFirst'))->toBeNull();
});

it('lets a custom withMethod() thrower enrich BOTH layers from one registration', function (): void {
    $custom = 'App\\Exceptions\\TeapotException';
    $registry = KnownThrowers::default()->withMethod(
        'brew',
        KnownThrower::withStatus($custom, 418),
    );

    // Layer 1 (explicit-throw enrichment): the exception FQCN now resolves to 418.
    expect($registry->statusForExceptionFqcn($custom))->toBe(418)
        ->and($registry->knownStatuses())->toHaveKey($custom);

    // Layer 2 (implicit-forwarder rescue): the same registration surfaces by name.
    $thrower = $registry->forMethod('brew');
    expect($thrower)->not->toBeNull()
        ->and($thrower->exceptionFqcn)->toBe($custom)
        ->and($thrower->fixedStatus)->toBe(418);
});

it('takes an application\'s own thrower on top of the built-in table', function (): void {
    // The config surface: an app naming its own `abort`-alike or `findOrFail`-alike keeps every built-in
    // entry, since a registry that replaced the table would silently stop documenting Laravel's own.
    $registry = KnownThrowers::default()
        ->withFunction('bail', KnownThrower::withStatus('App\\Exceptions\\BailException', 409))
        ->withMethod('soleOrFail', KnownThrower::withStatus('App\\Exceptions\\MissingException', 404));

    expect($registry->forFunction('bail')?->fixedStatus)->toBe(409)
        ->and($registry->forMethod('soleOrFail')?->fixedStatus)->toBe(404)
        ->and($registry->forFunction('abort'))->not->toBeNull()
        ->and($registry->forMethod('findOrFail')?->fixedStatus)->toBe(404);
});
