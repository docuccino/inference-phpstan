<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Integration;

use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Inference\PhpStan\Throwing\CalleeCatches;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * {@see CalleeCatches::CATCHES_BY_CONTRACT} is a hand-kept list, so it is checked against the framework the
 * fixture app installed: every global helper that runs a callable parameter inside a `try` whose catch does
 * not throw what it caught is one whose closure's throws never reach the caller. Stated here off the source
 * on its own terms, not by asking the class for its rule.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('lists every framework helper that swallows what the callable it runs throws', function (): void {
    $finder = new NodeFinder;
    $functions = 0;
    $swallowing = [];

    foreach (['src/Illuminate/Foundation/helpers.php', 'src/Illuminate/Support/helpers.php', 'src/Illuminate/Collections/helpers.php'] as $relative) {
        $file = FixtureRunner::appRoot().'/vendor/laravel/framework/'.$relative;
        $statements = (new ParserFactory)->createForHostVersion()->parse((string) file_get_contents($file)) ?? [];

        foreach ($finder->findInstanceOf($statements, Node\Stmt\Function_::class) as $function) {
            $functions++;
            $parameters = [];
            foreach ($function->params as $parameter) {
                if ($parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)) {
                    $parameters[] = $parameter->var->name;
                }
            }

            foreach ($finder->findInstanceOf($function->stmts, Node\Stmt\TryCatch::class) as $try) {
                $runs = $finder->findFirst($try->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Expr\Variable
                    && in_array($node->name->name, $parameters, true));

                foreach ($try->catches as $catch) {
                    $caught = $catch->var?->name;
                    $rethrown = $finder->findFirst($catch->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Throw_
                        && $node->expr instanceof Node\Expr\Variable
                        && $node->expr->name === $caught);

                    if ($runs !== null && $rethrown === null) {
                        $swallowing[] = $function->name->toString();
                    }
                }
            }
        }
    }

    // A scan that stopped seeing the helpers files would pass on an empty list.
    expect($functions)->toBeGreaterThan(40)
        ->and(array_values(array_unique($swallowing)))->toBe(CalleeCatches::CATCHES_BY_CONTRACT);
})->group('fixture');
