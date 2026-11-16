<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\FluentResponseChain;

/*
 * The links the chain reader names. Each must be a setter the framework's responses declare as handing the
 * same object back, or it is a name that can never fire; and each is read against the real engine by the
 * fixture app's chains, so every one must appear in them.
 */

/** @return array<string, string> link name → what it touches */
function fluentChainLinks(): array
{
    return (new ReflectionClassConstant(FluentResponseChain::class, 'LINKS'))->getValue();
}

it('names only setters the framework\'s responses declare as handing themselves back', function (string $name): void {
    $declared = array_values(array_filter(
        ['Illuminate\\Http\\JsonResponse', 'Illuminate\\Http\\Response', 'Illuminate\\Http\\RedirectResponse', 'Symfony\\Component\\HttpFoundation\\BinaryFileResponse'],
        static fn (string $class): bool => method_exists($class, $name),
    ));
    expect($declared)->not->toBe([]);

    foreach ($declared as $class) {
        $method = new ReflectionMethod($class, $name);
        $native = $method->getReturnType();
        $handsBack = ($native instanceof ReflectionNamedType && $native->getName() === 'static')
            || preg_match('/@return\s+(\$this|static)\b/', (string) $method->getDocComment()) === 1;

        expect($handsBack)->toBeTrue();
    }
})->with(static fn (): array => array_keys(fluentChainLinks()));

it('reads every link it names against the real engine', function (string $name): void {
    $chains = '';
    foreach (['ResponseChainController', 'StatementStatusController', 'WebhookReceiptController'] as $controller) {
        $chains .= (string) file_get_contents(dirname(__DIR__, 4).'/tests/fixture-app/src/app/Http/Controllers/'.$controller.'.php');
    }

    expect($chains)->toContain('->'.$name.'(');
})->with(static fn (): array => array_keys(fluentChainLinks()));

it('names a plausible number of links', function (): void {
    expect(count(fluentChainLinks()))->toBeGreaterThanOrEqual(10);
});
