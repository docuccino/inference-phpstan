<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\FacadeCall;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeFactoryFacade;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeFactoryRoot;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeLedger;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeLedgerFacade;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeRootlessFacade;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeUnboundFacade;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;

/**
 * A facade's static call is the same call on the object the facade resolves: `Response::json($b, 422)` is
 * `response()->json($b, 422)`, so the call is handed on with its name and arguments untouched, on a receiver
 * typed as that object. A class the analyser cannot read, one that is no facade, and a facade resolving
 * nothing have no such call.
 *
 * @param  list<string>  $unread  classes PHP can load and the analyser cannot read
 */
function facadeCallOf(string $code, array $unread = []): ?Node\Expr\MethodCall
{
    $call = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';')[0]->expr;
    expect($call)->toBeInstanceOf(Node\Expr\StaticCall::class);

    $scope = test()->createStub(Scope::class);
    $scope->method('resolveName')->willReturnCallback(static fn (Node\Name $name): string => $name->toString());

    $reflection = test()->createStub(ReflectionProvider::class);
    $reflection->method('hasClass')->willReturnCallback(static fn (string $class): bool => class_exists($class) && ! in_array($class, $unread, true));

    return FacadeCall::onRoot($call, $scope, $reflection);
}

it('reads a facade call as the same call on the object the facade resolves', function (string $facade, string $root): void {
    $call = facadeCallOf('\\'.$facade.'::json([\'error\' => \'locked\'], status: 422)');

    expect($call)->not->toBeNull()
        ->and($call?->var)->toBeInstanceOf(TypeExpr::class)
        ->and($call?->var instanceof TypeExpr ? $call->var->getExprType() : null)->toEqual(new ObjectType($root))
        ->and($call === null ? null : (new Standard)->prettyPrintExpr(new Node\Expr\MethodCall(new Node\Expr\Variable('root'), $call->name, $call->args)))
        ->toBe('$root->json([\'error\' => \'locked\'], status: 422)');
})->with([
    'a facade proxying a response factory' => [ProbeFactoryFacade::class, ProbeFactoryRoot::class],
    // Whatever the root is: deciding which calls on it mean anything is the reader's business.
    'a facade proxying anything else' => [ProbeLedgerFacade::class, ProbeLedger::class],
]);

it('keeps where the call was written', function (): void {
    $call = facadeCallOf('\\'.ProbeFactoryFacade::class.'::noContent(205)');

    expect($call?->getStartLine())->toBe(1);
});

it('has no call on a root where there is none to stand in for', function (string $code, array $unread = []): void {
    expect(facadeCallOf($code, $unread))->toBeNull();
})->with([
    'a class that is no facade' => ['\\'.ProbeLedger::class.'::json([])'],
    'a facade resolving nothing' => ['\\'.ProbeRootlessFacade::class.'::json([])'],
    // Asking an unbound facade for its root throws; a build is no place for that to surface.
    'a facade whose root throws' => ['\\'.ProbeUnboundFacade::class.'::json([])'],
    'a class that does not exist' => ['\\Docuccino\\Missing\\Facade::json([])'],
    // The analyser types no call on a class it cannot read, so there is no call to stand in for.
    'a facade the analyser cannot read' => ['\\'.ProbeFactoryFacade::class.'::json([])', [ProbeFactoryFacade::class]],
    'a class named at run time' => ['$facade::json([])'],
]);
