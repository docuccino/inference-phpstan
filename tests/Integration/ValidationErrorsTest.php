<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A failed validation's messages put in a body (`app/Http/Controllers/ValidationErrorsController.php`). The
 * framework sends `ValidationException::errors()` as its validator's `errors()->messages()`: each failed field
 * to the list of its messages, which `json_encode` makes an object keyed by field. So it reads as that map,
 * whichever way the application reaches it, and not as the list a bare `array` declaration reads as.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('reads a failed validation’s messages as each field’s list of messages, however they are reached', function (): void {
    $methods = ['exceptionErrors', 'inheritedErrors', 'bagMessages', 'bagArray', 'bagJson', 'overriddenErrors'];
    $read = [];
    foreach (FixtureRunner::analyzeMany('app/Http/Controllers/ValidationErrorsController.php', 'App\\Http\\Controllers\\ValidationErrorsController', $methods) as $method => $raw) {
        $analysis = ActionAnalysis::fromArray($raw);
        expect($analysis->returns)->toHaveCount(1);
        $response = $analysis->returns[0]->type;
        assert($response instanceof ClassT);
        $body = $response->typeArgs[0] ?? null;
        assert($body instanceof ArrayShapeT);
        $read[$method] = $body->fields[0]->type->toArray();
    }

    $byField = (new MapT(ScalarT::string(), new ListT(ScalarT::string())))->toArray();

    expect($read)->toBe([
        'exceptionErrors' => $byField,
        // A subclass that leaves `errors()` alone sends the framework's map.
        'inheritedErrors' => $byField,
        'bagMessages' => $byField,
        // A release that declares these a bare `array` reads them as the same map.
        'bagArray' => $byField,
        'bagJson' => $byField,
        // One that overrides it sends what its override returns.
        'overriddenErrors' => (new ListT(ScalarT::string()))->toArray(),
    ]);
})->group('fixture');
