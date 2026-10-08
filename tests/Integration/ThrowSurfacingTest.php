<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * Which of an action's throws become API errors at all, against the real engine: abort status
 * folding, registry enrichment + rescue, bounded descent, `@throws` trust and catch subtraction.
 * What STATUS the surfaced error then carries is {@see ThrowStatusTest}.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('surfaces exactly the expected API errors', function (string $method, array $expected): void {
    sort($expected);

    expect(signalThrows($method))->toBe($expected);
})->with([
    'abort + abort_if, both statuses folded' => ['abortAction', ['HttpException@403', 'HttpException@404']],
    // The same two calls with the status named rather than counted. PHPStan hands throw points the
    // NORMALIZED call, so a named argument already sits in the position the registry indexes — pinned
    // here because the day that stops being true, both statuses vanish without a word.
    'abort + abort_if, statuses named' => ['namedAbortAction', ['HttpException@418', 'HttpException@451']],
    'authorize → 403' => ['authorizeAction', ['AuthorizationException@403']],
    'static findOrFail rescued → 404' => ['findOrFailAction', ['ModelNotFoundException@404']],
    'inline validate → 422' => ['validateAction', ['ValidationException@422']],
    '2-deep descent, no @throws' => ['deepUndeclared', ['OutOfStockException@500', 'RuntimeException@500']],
    '@throws trusted, deeper hidden' => ['deepDeclared', ['OutOfStockException@500']],
    'vendor any-throwable = no API error' => ['anyThrowableNoise', []],
    'caught subtracted, escaping surfaced' => ['tryCatch', ['RuntimeException@500']],
    // The same catch around a call that DECLARES what it throws. The point that survives the catch names no
    // class at all, and a declaring callee is not descended for what it might throw besides — doing so
    // publishes the very exceptions the action turns into a 200.
    'caught across a declaring call' => ['tryCatchDeclared', ['RuntimeException@500']],
    // The catch takes what the callee declares and nothing its closure argument throws: the closure is
    // the action's own code, read whatever became of the call's declared classes.
    'caught declaring call, closure argument escapes' => ['tryCatchDeclaredClosure', ['OutOfStockException@500', 'RuntimeException@500']],
    // …and the catch is in force over the closure all the same: what it takes is not published.
    'caught inside a closure argument' => ['tryCatchClosure', ['RuntimeException@500']],
    // The same catch around a call that declares NOTHING. What it throws is found by descending into it, and
    // the catch in the action's own body is what decides which of those leave: PHP hands a catch everything
    // the try's statements raise that is an instance of a class it names, whether the analyser saw a
    // declaration or not. Read from the source rather than off the analyser's point, which names the residue
    // on one PHPStan minor and plain `Throwable` on the one before it.
    'caught across an undeclared call' => ['caughtUndeclared', ['RuntimeException@500']],
    // A declaring callee is not descended for what it hides, caught or not: what the catch leaves of a
    // `@throws` is the `@throws` minus the catch, which is nothing here, as `deepDeclared` publishes only the
    // declared class. Descending anyway would answer the hidden RuntimeException on PHPStan 2.3, which keeps
    // a point for the residue, and nothing on 2.2, which does not.
    'caught across a declaring call that hides more' => ['caughtDeclaredWithResidue', ['LogicException@500']],
    'caught by a base class of both' => ['caughtUndeclaredByParent', ['LogicException@500']],
    'caught by an interface of both' => ['caughtUndeclaredByInterface', ['LogicException@500']],
    'caught by a multi-catch naming both' => ['caughtUndeclaredMulti', ['LogicException@500']],
    // What a catch does next is its own throw point, read where it is written: a rethrow still leaves, and a
    // translation leaves as the class it translates to.
    'caught and rethrown' => ['caughtUndeclaredRethrown', ['OutOfStockException@500', 'RuntimeException@500']],
    // A catch that rethrows its own variable takes nothing: the `throw $e` lets out whatever the try raised,
    // which a catch wider than every class it names cannot spell — so the descended classes are what say it.
    'caught wide and rethrown' => ['caughtWideRethrown', ['OutOfStockException@500', 'RuntimeException@500']],
    'rolled back and rethrown' => ['caughtRolledBack', ['OutOfStockException@500', 'RuntimeException@500']],
    // …and around a declaring call, the rethrow lets out what it declares and not what it hides.
    'rolled back and rethrown, declared' => ['caughtRolledBackDeclared', ['OutOfStockException@500']],
    'rolled back and rethrown, a throw in the try' => ['caughtRolledBackLiteral', ['OutOfStockException@500', 'RuntimeException@500']],
    'rethrown on one path' => ['caughtSometimesRethrown', ['OutOfStockException@500', 'RuntimeException@500']],
    // A catch that hands what it caught to a call lets it out unless the callee is shown to keep it: a helper
    // that rethrows is as common as a `throw $e`, and the catch alone cannot tell the two apart. report()
    // passes it on to the exception handler, which no read here can follow, so it keeps nothing back.
    'caught and handed to report()' => ['caughtAndReported', ['OutOfStockException@500', 'RuntimeException@500']],
    'caught and handed to a helper that rethrows' => ['caughtHandedToRethrower', ['OutOfStockException@500', 'RuntimeException@500']],
    'caught around a declaring call, handed to a helper that rethrows' => ['caughtDeclaredHandedToRethrower', ['OutOfStockException@500']],
    'caught and handed to a static helper that rethrows' => ['caughtHandedToStaticRethrower', ['OutOfStockException@500', 'RuntimeException@500']],
    // …and a helper whose body reads it only for its message keeps it, so the catch takes what it names.
    'caught and handed to a helper that only logs' => ['caughtHandedToLogger', ['LogicException@500']],
    // A class either side of the test cannot reflect is kept: a catch whose class no file declares takes
    // nothing, and a thrown class no file declares cannot be shown to be an instance of the catch.
    'a catch of an unknown class' => ['caughtUnknownClass', ['OutOfStockException@500', 'RuntimeException@500']],
    'an unknown class thrown under a catch' => ['caughtUnknownThrown', ['NoSuchThrownException@500']],
    'caught and translated' => ['caughtUndeclaredTranslated', ['LogicException@500', 'RuntimeException@500']],
    'caught by nested tries, one each' => ['caughtUndeclaredNested', ['LogicException@500']],
    // The two shapes that take nothing: a `finally` alone, and a catch of a SUBCLASS of what is thrown — the
    // thrown class is not an instance of it, so the catch may take some instances and the rest still leave.
    'a finally with no catch' => ['undeclaredInFinallyOnly', ['OutOfStockException@500', 'RuntimeException@500']],
    'a catch narrower than the throw' => ['caughtUndeclaredNarrower', ['OutOfStockException@500', 'RuntimeException@500']],
    // The same subtraction one level down, written in the callee's body around a call it descends into.
    'caught inside the descended callee' => ['caughtInsideCallee', ['OutOfStockException@500']],
    // …and across the two other ways a throw reaches the action as a call: a closure the callee runs, and
    // a callee only the registry can read.
    'caught around a closure the callee runs' => ['caughtClosureThrow', ['ExportUnsupportedException@422']],
    'caught around a registry-read call' => ['caughtFindOrFail', ['HttpException@410']],
    // A catch the CALLEE writes around its own call of the work it was handed takes what that work throws,
    // as one around the call to the callee would: PHP hands it everything the try's statements raise.
    'swallowed by rescue()' => ['rescuedClosure', ['LogicException@500']],
    "swallowed by the app's own helper" => ['swallowedByHelper', ['LogicException@500']],
    "swallowed by the app's own helper, on an instance" => ['swallowedByHelperOnInstance', ['LogicException@500']],
    "swallowed by the app's own helper function" => ['swallowedByHelperFunction', ['LogicException@500']],
    // …and only what it takes: a catch that rethrows takes nothing, a narrower one takes its class alone, and
    // a run of the work from inside the catch is one no catch is around.
    'reported and rethrown by a helper' => ['rethrownByHelper', ['OutOfStockException@500', 'RuntimeException@500']],
    'narrowly swallowed by a helper' => ['narrowlySwallowedByHelper', ['RuntimeException@500']],
    'retried by a helper from its catch' => ['retriedByHelper', ['OutOfStockException@500', 'RuntimeException@500']],
    // A helper's catch is read by the same rule as the action's: one that hands what it caught to a method
    // that rethrows it takes nothing. And a helper that hands the work on names no place it runs, so no catch
    // of its own is weighed.
    'handed by a helper to a method that rethrows' => ['failedByHelper', ['OutOfStockException@500', 'RuntimeException@500']],
    'relayed by a helper to another' => ['relayedByHelperFunction', ['OutOfStockException@500', 'RuntimeException@500']],
    // The registry is keyed on a bare method name, so an app's own validate() is exactly where a guess
    // could overrule a truth: the callee is project code we read, so its own exception stands and no
    // ValidationException/422 is invented for it.
    "the app's own validate() keeps its own exception" => ['projectValidate', ['OutOfStockException@500']],
])->group('fixture');

it('depends on the file of a class a catch took', function (string $method): void {
    // Whether a catch takes a class is read off that class's ancestry, so its file decides what the route
    // publishes even when the answer is to publish nothing: re-parenting the dropped exception lets it out,
    // and a warm build keyed without the file would go on dropping what a cold one publishes.
    expect(throwDependencyNames($method))->toContain('OutOfStockException.php');
})->with([
    'caught by its own class' => ['caughtUndeclared'],
    'caught by a base class' => ['caughtUndeclaredByParent'],
])->group('fixture');

it('depends on the file of a helper a catch was shown to keep its exception by', function (): void {
    // The helper's body is what lets the catch take anything at all, so editing it to rethrow has to reach
    // a warm build.
    expect(throwDependencyNames('caughtHandedToLogger'))->toContain('ProbeGuards.php');
})->group('fixture');

it('invalidates a cached fragment when a class a catch took is edited', function (): void {
    /** @var list<string> $dependencies */
    $dependencies = throwsAnalysis('caughtUndeclaredByParent')['dependencyFiles'];

    expect(fragmentAcrossDependencyEdit($dependencies, 'app/Exceptions/OutOfStockException.php'))
        ->toBe(['warm' => true, 'staleAfterEdit' => true]);
})->group('fixture');
