<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\TraitUsingRenderer;
use Docuccino\Inference\PhpStan\Trace\Callee;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * The resolved call target, whose {@see Callee::key()} is what the descent memoises and cycle-guards on.
 * It names the DECLARING class rather than the receiver's, so one inherited helper is walked once however
 * many subclasses reach it — and two subclasses cannot each spend the budget on the same body.
 */
it('identifies a callee by its declaring class and method', function (): void {
    $callee = new Callee('App\\Exceptions\\ProblemRenderer', 'render', '/app/Exceptions/ProblemRenderer.php');

    expect($callee->key())->toBe('App\\Exceptions\\ProblemRenderer::render')
        ->and((new Callee('App\\Exceptions\\ProblemRenderer', 'render', '/elsewhere.php'))->key())
        ->toBe($callee->key())
        // With no second file given, where the body is written is where the class is.
        ->and($callee->writtenIn())->toBe('/app/Exceptions/ProblemRenderer.php');
});

it('names the file a body was written in apart from the one its class is', function (): void {
    // PHP reports a trait-imported method as the USING class's, so the declaring class's file is not where
    // the body — or the `@throws` on it — is written. Both are dependencies: the harvest comes off one and
    // the decision was written in the other.
    $callee = new Callee(
        'App\\Http\\Controllers\\ProbeController',
        'guard',
        '/app/Http/Controllers/ProbeController.php',
        '/app/Support/Concerns/Guards.php',
    );

    expect($callee->writtenIn())->toBe('/app/Support/Concerns/Guards.php')
        // …and identity is still the declaring class's, so one shared guard is walked once.
        ->and($callee->key())->toBe('App\\Http\\Controllers\\ProbeController::guard');
});

it('leaves a trace root whose declaration cannot be located on the file it was given', function (string $class): void {
    // The root arrives as a class/method/file rather than as a call to resolve, and it makes the same
    // declaration read every callee gets — asked of the ANALYSER's reflection, which locates a declaration
    // by reading files. A provider that knows neither of these names answers nothing, and the root's
    // accounting degrades to the file the caller handed over rather than being dropped.
    //
    // A trait-imported action really being keyed with the TRAIT's file is the other half, and it needs the
    // real analyser: TraceDependencyTest's `ListsExports.php` rows are that half.
    $resolver = new CalleeResolver($this->createStub(ReflectionProvider::class));

    $root = $resolver->root($class, 'traitDeclared', '/app/TraitUsingRenderer.php');

    expect($root->writtenIn())->toBe('/app/TraitUsingRenderer.php')
        ->and($root->key())->toBe($class.'::traitDeclared');
})->with([
    'a class the analyser has no declaration for' => ['App\\Nowhere\\Absent'],
    // …and one this process really could reflect on, which is the point: nothing but the provider is asked.
    'a class only native reflection knows' => [TraitUsingRenderer::class],
]);

it('locates a declaration through the analyser, never from a class NAME', function (): void {
    // A name handed to native reflection is a name AUTOLOADED, and autoloading a class executes the file
    // that declares it — so reading a callee's declaration that way makes the generator run analysed code
    // at every resolved call in every walked body, vendor included. Measured over one Query-Builder trace
    // of one fixture action: three classes the process had not loaded would have been loaded, one of them
    // a vendor internal the application never instantiates.
    //
    // Two halves, because either on its own passes the wrong code. The parameter type is what leaves no
    // name there to load — a resolved `ClassReflection`, which the analyser located by reading files. The
    // source scan is what catches a body that fetches the name back off it, which no signature can. A
    // behavioural row can stand in for neither: the read is only reached once the provider has resolved
    // the class, which needs the real analyser rather than a stub.
    //
    // The scan tokenises rather than greps, for the reason the boundary scans do: the docblock beside the
    // read names `new ReflectionMethod` to say what it does not do, and raw text calls that a hit.
    $code = implode('', array_map(
        static fn (array|string $token): string => match (true) {
            is_string($token) => $token,
            in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) => '',
            default => $token[1],
        },
        token_get_all((string) file_get_contents(dirname(__DIR__, 2).'/src/Trace/CalleeResolver.php')),
    ));

    $type = (new ReflectionMethod(CalleeResolver::class, 'writtenIn'))->getParameters()[0]->getType();

    expect($type)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($type->getName())->toBe(ClassReflection::class)
        ->and($code)->not->toContain('new Reflection')
        // The floor: the read it DOES make, so a scan that stopped recognising this file fails loudly
        // rather than passing on a shape it no longer finds.
        ->and($code)->toContain('getNativeReflection()->getMethod(');
});
