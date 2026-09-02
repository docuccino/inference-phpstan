<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;
use Docuccino\Inference\PhpStan\Throwing\AnalyzedBodies;
use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\StringType;

/**
 * The analyser's side of the {@see ClassBodies} seam. Its twin,
 * `ParsedBodies`, is what every status-read test folds through, so what is proven here is the half only
 * this implementation has: that a constant is folded in the scope PHPStan paired with the CALL, and that
 * an answer nothing was harvested for is null rather than a guess.
 *
 * A real `MethodReturnStatementsNode` needs a container, so the method-bearing halves are proven where
 * they run — the `fixture` group. Their empty-harvest answers are here, because "no body" is the
 * degradation the status read is written to.
 */
function analyzedBodiesOver(string $code, ?Scope $scope): array
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';') ?? [];

    /** @var Node\Expr\New_|null $call */
    $call = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Expr\New_::class);
    expect($call)->not->toBeNull();

    $adapter = new ScriptedRuntimeAdapter(['/x.php' => $scope === null ? [] : [[$call, $scope]]]);

    return [new AnalyzedBodies(new FileAnalyzer($adapter, new FileWalks($adapter))), $call];
}

it('folds a construction argument in the scope the walk paired with that call', function (): void {
    $scope = $this->createStub(Scope::class);
    $scope->method('getType')->willReturn(new ConstantIntegerType(409));

    [$bodies, $call] = analyzedBodiesOver('new X(409)', $scope);

    expect($bodies->foldInt('/x.php', $call->getArgs()[0]->value, $call))->toBe(409);
});

it('folds nothing where the scope answers a type that is not one constant integer', function (): void {
    $scope = $this->createStub(Scope::class);
    $scope->method('getType')->willReturn(new StringType);

    [$bodies, $call] = analyzedBodiesOver('new X($runtime)', $scope);

    expect($bodies->foldInt('/x.php', $call->getArgs()[0]->value, $call))->toBeNull();
});

it('folds nothing for a call the walk never paired a scope with', function (): void {
    // The file was walked and this call is not in it — a stripped body, or a node from another parse.
    // Answering off some other scope would fold the argument against variables it never held.
    [$bodies, $call] = analyzedBodiesOver('new X(409)', null);

    expect($bodies->foldInt('/x.php', $call->getArgs()[0]->value, $call))->toBeNull();
});

it('answers no bodies and no default for a file whose harvest holds no method', function (): void {
    // The `inference.method-not-found` shape one layer down: the status read asks for a constructor it
    // will not get, and both answers have to be "nothing" rather than something borrowed.
    [$bodies] = analyzedBodiesOver('new X(409)', null);

    expect($bodies->methods('/x.php', 'App\\Exceptions\\Rejected'))->toBe([])
        ->and($bodies->intDefault('/x.php', 'App\\Exceptions\\Rejected', '__construct', 0))->toBeNull();
});
