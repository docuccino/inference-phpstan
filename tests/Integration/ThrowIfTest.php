<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * `throw_if($condition, $exception, ...$parameters)` and `throw_unless()` throw the instance they are handed,
 * `new $exception(...$parameters)` for a class name, or a `RuntimeException` of any other string, and a
 * closure is called for one first. So the request gets the response that `throw` would carry. Their generic
 * `@throws` names at most the class, and the construction that decides the status is the argument.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> every ThrowIfController action, by name */
function throwIfActions(): array
{
    static $analyses = null;

    if ($analyses === null) {
        $file = (string) file_get_contents(FixtureRunner::path('app/Http/Controllers/ThrowIfController.php'));
        preg_match_all('/public function (\w+)\(/', $file, $found);

        /** @var array<string, array<string, mixed>> $analyses */
        $analyses = FixtureRunner::analyzeMany(
            'app/Http/Controllers/ThrowIfController.php',
            'App\\Http\\Controllers\\ThrowIfController',
            $found[1],
        );
    }

    return $analyses;
}

/**
 * What reaches the document for one action: each error it publishes, its responses' statuses, and the
 * unread-status notices it raised.
 *
 * @return array{throws: list<string>, returns: list<string>, unread: int}
 */
function throwIfFacts(string $method): array
{
    $analysis = throwIfActions()[$method];

    $throws = [];
    /** @var list<array<string, mixed>> $thrown */
    $thrown = $analysis['throws'];
    foreach ($thrown as $throw) {
        if (($throw['disposition'] ?? null) === 'signal') {
            $class = explode('\\', (string) $throw['exceptionFqcn']);
            $throws[] = end($class).'@'.($throw['httpStatusHint'] ?? 'null');
        }
    }
    sort($throws);

    $returns = [];
    /** @var list<array{type: array{typeArgs?: list<array{value?: mixed}>}}> $sites */
    $sites = $analysis['returns'];
    foreach ($sites as $site) {
        $returns[] = (string) json_encode($site['type']['typeArgs'][1]['value'] ?? null);
    }

    /** @var list<array<string, mixed>> $diagnostics */
    $diagnostics = $analysis['diagnostics'];
    $unread = count(array_filter($diagnostics, static fn (array $d): bool => ($d['code'] ?? null) === 'inference.http-exception-status-unread'));

    return ['throws' => $throws, 'returns' => $returns, 'unread' => $unread];
}

it('refuses with the exception a throw_if() is handed, at the status its construction carries', function (string $method, string $throws): void {
    expect(throwIfFacts($method))->toBe(['throws' => [$throws], 'returns' => ['200'], 'unread' => 0]);
})->with([
    'an instance' => ['ifInstance', 'HttpException@409'],
    'an instance, throw_unless()' => ['unlessInstance', 'ExportLockedException@423'],
    'an instance held in a local' => ['ifLocal', 'ExportLockedException@423'],
    'an instance from a static factory' => ['ifFactory', 'ExportConflictException@403'],
    'an instance built on an enum case' => ['ifEnumInstance', 'ExportFaultException@423'],
    'its arguments named' => ['ifNamed', 'HttpException@409'],
    // `new $exception(...$parameters)`: the parameters are the constructor's, by position or by name.
    'a class name and its parameters' => ['ifClassString', 'ExportLockedException@423'],
    'a class name, throw_unless()' => ['unlessClassString', 'HttpException@409'],
    'a class name and a parameter by name' => ['ifNamedParameter', 'ExportLockedException@423'],
    'a class name and an enum case' => ['ifEnumClassString', 'ExportFaultException@410'],
    'a class name and a reason case' => ['unlessReasonClassString', 'Refused@401'],
    // A closure is called, and what it returns is thrown.
    'a closure' => ['ifClosure', 'HttpException@410'],
    // Any other string is a `RuntimeException`'s message, and the default is that class's name: a literal
    // `throw new RuntimeException(…)` in the action, published as one.
    'a message' => ['ifMessage', 'RuntimeException@500'],
    'nothing' => ['ifDefault', 'RuntimeException@500'],
])->group('fixture');

it('documents the response a throw_if() hands the framework to send', function (): void {
    expect(throwIfFacts('ifCarried'))->toBe(['throws' => [], 'returns' => ['200', '429'], 'unread' => 0]);
})->group('fixture');

it('says a status chosen at run time is unread, as it does at a throw', function (string $method, string $throws): void {
    // Still an error the request can get, so it is published; the notice points at the line to pin.
    expect(throwIfFacts($method))->toBe(['throws' => [$throws], 'returns' => ['200'], 'unread' => 1]);
})->with([
    'an instance' => ['ifChosen', 'HttpException@null'],
    'a class name' => ['ifClassStringChosen', 'HttpException@null'],
    // A reason that may be any case has the status they agree on, and these do not.
    'a reason case' => ['unlessReasonChosen', 'Refused@null'],
])->group('fixture');

it('drops what a catch around a throw_if() takes', function (): void {
    expect(throwIfFacts('ifCaught')['throws'])->toBe([]);
})->group('fixture');

it('reads the status a declaring guard hands throw_if()', function (): void {
    // The `@throws` names the class, and the guard's `throw_if()` is where it is built.
    expect(throwIfFacts('declaredGuard')['throws'])->toBe(['HttpException@409']);
})->group('fixture');

it('publishes no error a guard is never handed the condition to throw', function (string $method): void {
    // `throw_if(false, …)` and `abort_if(false, …)` return without throwing, so no request gets the error.
    expect(throwIfFacts($method))->toBe(['throws' => [], 'returns' => ['200'], 'unread' => 0]);
})->with([
    'throw_if(false)' => ['ifFalse'],
    'throw_unless(true)' => ['unlessTrue'],
    'throw_if() on a false class constant' => ['ifReadOnly'],
    'abort_if(false)' => ['abortIfFalse'],
    'abort_unless(true)' => ['abortUnlessTrue'],
    'abort_if() on a false class constant' => ['abortIfReadOnly'],
    // `report_if()` reports the exception and carries on.
    'report_if()' => ['reportIf'],
])->group('fixture');

it('still publishes the error a guard is handed the condition to throw', function (string $method, string $throws): void {
    expect(throwIfFacts($method)['throws'])->toBe([$throws]);
})->with([
    'throw_if(true)' => ['ifTrue', 'HttpException@409'],
    'throw_unless(false)' => ['unlessFalse', 'HttpException@409'],
    'abort_if(true)' => ['abortIfTrue', 'HttpException@403'],
    // The condition is settled by what PHP enforces, and a docblock is not that: a request with `busy` set
    // gets the 409 whatever the `@var` says.
    'a condition only a docblock calls false' => ['ifDocumentedFalse', 'HttpException@409'],
])->group('fixture');

it('depends on the files a handed exception\'s status is written in', function (string $method, array $files): void {
    /** @var list<string> $dependencies */
    $dependencies = throwIfActions()[$method]['dependencyFiles'];

    expect(array_map('basename', $dependencies))->toContain(...$files);
})->with([
    'the class and the enum it is built on' => ['ifEnumClassString', ['ExportFaultException.php', 'ExportFault.php']],
    'the class and the reason enum its parent reads' => ['unlessReasonClassString', ['Refused.php', 'Refusal.php', 'TokenRefusal.php']],
    'the class whose factory it names' => ['ifFactory', ['ExportConflictException.php']],
])->group('fixture');
