<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Trace\Callee;

/**
 * The resolved call target, whose {@see Callee::key()} is what the descent memoises and cycle-guards on.
 * It names the DECLARING class rather than the receiver's, so one inherited helper is walked once however
 * many subclasses reach it — and two subclasses cannot each spend the budget on the same body.
 */
it('identifies a callee by its declaring class and method', function (): void {
    $callee = new Callee('App\\Exceptions\\ProblemRenderer', 'render', '/app/Exceptions/ProblemRenderer.php');

    expect($callee->key())->toBe('App\\Exceptions\\ProblemRenderer::render')
        ->and((new Callee('App\\Exceptions\\ProblemRenderer', 'render', '/elsewhere.php'))->key())
        ->toBe($callee->key());
});
