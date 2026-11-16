<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Inference\PhpStan\Analysis\ForwardedSite;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;

/*
 * A site handing the narrowed exception on to a helper is answered by the helper. Which call it hands it to
 * decides whether the helper is followed at all, and the site standing for it afterwards decides where the
 * answer is reached and what it is: reached as the renderer's own site was, built as the helper builds it.
 */

it('follows the call a site hands the exception to', function (Node\Expr $expr, bool $rendersOwn, ?string $expected): void {
    $call = ForwardedSite::call($expr, static fn (Node\Expr $receiver): bool => $rendersOwn);

    expect($call === null ? null : (new Standard)->prettyPrintExpr($call))->toBe($expected);
})->with(function (): array {
    $e = new Node\Arg(new Node\Expr\Variable('e'));
    $for = new Node\Expr\StaticCall(new Node\Name('Problems'), 'for', [$e]);

    return [
        'a static helper' => [$for, false, 'Problems::for($e)'],
        'a function' => [new Node\Expr\FuncCall(new Node\Name('problem'), [$e]), false, 'problem($e)'],
        'a method' => [new Node\Expr\MethodCall(new Node\Expr\Variable('this'), 'problem', [$e]), false, '$this->problem($e)'],
        // Sending the object is the response returning it would be, where the application wrote how it sends.
        'the object its own toResponse() sends' => [new Node\Expr\MethodCall($for, 'toResponse', [new Node\Arg(new Node\Expr\Variable('request'))]), true, 'Problems::for($e)'],
        'the same spelled in another case' => [new Node\Expr\MethodCall($for, 'TOResponse'), true, 'Problems::for($e)'],
        // A toResponse() the application did not write is the call itself, and the framework's own to read.
        'a framework toResponse()' => [new Node\Expr\MethodCall($for, 'toResponse'), false, 'Problems::for($e)->toResponse()'],
        'a first-class callable' => [new Node\Expr\StaticCall(new Node\Name('Problems'), 'for', [new Node\VariadicPlaceholder]), false, null],
        'no call at all' => [new Node\Expr\Variable('problem'), false, null],
        'a construction' => [new Node\Expr\New_(new Node\Name('Problem'), [$e]), false, null],
    ];
});

/** @return array{pos: int, line: int, type: DType, component: ComponentDeclaration|null, guard: list<list<string>>, delegates: bool, echoes: string|null, conditions: list<never>, typeConditions: list<never>, reachedWhen: null, scope: Scope|null, expr: Node\Expr|null, foldScope: Scope} */
function forwardedSite(int $pos, DType $type, bool $delegates, ?ComponentDeclaration $component, Node\Expr $expr, Scope $scope, array $guard = []): array
{
    return [
        'pos' => $pos, 'line' => $pos, 'type' => $type, 'component' => $component, 'guard' => $guard, 'delegates' => $delegates,
        'echoes' => null, 'conditions' => [], 'typeConditions' => [], 'reachedWhen' => null, 'scope' => $scope, 'expr' => $expr, 'foldScope' => $scope,
    ];
}

it('stands for the site with the helper’s answer, reached as the site was', function (): void {
    $outerScope = Mockery::mock(Scope::class);
    $innerScope = Mockery::mock(Scope::class);
    $below = new ComponentDeclaration('Problem', 'App\\Problems::of', new SourceLocation('Problems.php', 9));
    $declared = new ComponentDeclaration('ApiProblem', 'App\\Problems::for', new SourceLocation('Problems.php', 3));
    $site = forwardedSite(10, new ClassT('App\\Problem'), false, null, new Node\Expr\Variable('outer'), $outerScope, [['App\\Refused']]);
    $chosen = forwardedSite(90, new ClassT('Illuminate\\Http\\JsonResponse'), false, $below, new Node\Expr\Variable('inner'), $innerScope);

    $answered = ForwardedSite::answeredBy($site, $chosen, $declared);

    // Where it is reached, and whether, stay the renderer's: its position and its guard.
    expect([$answered['pos'], $answered['line'], $answered['guard'], $answered['scope']])->toBe([10, 10, [['App\\Refused']], $outerScope])
        // What it answers with is the helper's, read where the helper reads it.
        ->and([$answered['type'], $answered['expr'], $answered['foldScope']])->toBe([$chosen['type'], $chosen['expr'], $innerScope])
        // And the helper names the body over the hop below it.
        ->and($answered['component'])->toBe($declared)
        ->and(ForwardedSite::answeredBy($site, $chosen, null)['component'])->toBe($below);
});

it('carries no name the helper declares for a delegation', function (): void {
    $scope = Mockery::mock(Scope::class);
    $site = forwardedSite(1, new ClassT('App\\Problem'), false, null, new Node\Expr\Variable('outer'), $scope);
    $chosen = forwardedSite(2, new NullT, true, null, new Node\Expr\ConstFetch(new Node\Name('null')), $scope);

    $answered = ForwardedSite::answeredBy($site, $chosen, new ComponentDeclaration('ApiProblem', 'App\\Problems::render', new SourceLocation('Problems.php', 3)));

    expect($answered['delegates'])->toBeTrue()
        ->and($answered['component'])->toBeNull();
});
