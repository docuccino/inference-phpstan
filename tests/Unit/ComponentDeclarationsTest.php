<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\ComponentDeclarations;
use Docuccino\Inference\PhpStan\Tests\Support\DeclaresProblems;
use Docuccino\Inference\PhpStan\Tests\Support\DeclaringRenderer;
use Docuccino\Inference\PhpStan\Tests\Support\InheritingRenderer;
use Docuccino\Inference\PhpStan\Tests\Support\TraitUsingRenderer;

/**
 * The `#[ErrorComponent]` a render method declares, read off reflection. What the whole render PATH
 * resolves to — outermost declaring hop, and the file that hop was written in — is engine truth, proved
 * against real code in the `--group=fixture` InferredHandlerTest.
 */
it('reads the name a render method declares, however the argument was written', function (string $method, ?string $name): void {
    $declaration = ComponentDeclarations::onMethod(new ReflectionMethod(DeclaringRenderer::class, $method));

    expect($declaration?->name)->toBe($name);
})->with([
    'positional argument' => ['positional', 'PortalRejection'],
    'named argument' => ['named', 'PortalThrottle'],
    // Read verbatim: refusing it is the reader's job, not this one's, and the reporter needs the string
    // the author actually wrote in order to quote it back at them.
    'a name no component key could carry' => ['illegal', 'Not Found!'],
    'nothing declared' => ['undeclared', null],
]);

it('reports the trait a render method was written in, not the class that uses it', function (): void {
    // Reflection reports a trait-imported method as the USING class's own while its file stays the trait's,
    // so a symbol taken from the declaring class alone would send the reader to a class whose file has no
    // attribute in it. Two traits deep, because the outer one reports the inner one's method as its own.
    $declaration = ComponentDeclarations::onMethod(new ReflectionMethod(TraitUsingRenderer::class, 'traitDeclared'));

    expect($declaration?->name)->toBe('TraitProblem')
        ->and($declaration?->symbol)->toBe(DeclaresProblems::class.'::traitDeclared')
        ->and(basename($declaration?->location->file ?? ''))->toBe('DeclaresProblems.php');
});

it('reports the class that really declares an inherited render method', function (): void {
    // PHP resolves an unoverridden method to the parent that declared it, so inheritance costs no walk —
    // and the symbol and file have to name that parent, which is where the reader must go to change it.
    $declaration = ComponentDeclarations::onMethod(new ReflectionMethod(InheritingRenderer::class, 'positional'));

    expect($declaration?->name)->toBe('PortalRejection')
        ->and($declaration?->symbol)->toBe(DeclaringRenderer::class.'::positional')
        ->and(basename($declaration?->location->file ?? ''))->toBe('DeclaringRenderer.php')
        ->and($declaration?->location->line)->toBeGreaterThan(0);
});
