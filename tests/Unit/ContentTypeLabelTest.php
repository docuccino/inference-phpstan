<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\ContentTypeLabel;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Real file positions are the whole mechanism, so each case is PARSED rather than hand-built: the label
 * only counts when the header write sits between the returned variable's last assignment and the return.
 *
 * @return array{stmts: array<Node\Stmt>, returns: list<Node\Expr>}
 */
function ctlParse(string $body): array
{
    $ast = (new ParserFactory)->createForHostVersion()->parse("<?php\nfunction handle(bool \$flag) {\n".$body."\n}\n") ?? [];
    $function = $ast[0];
    assert($function instanceof Node\Stmt\Function_);

    $returns = [];
    foreach ((new NodeFinder)->findInstanceOf($function->stmts, Node\Stmt\Return_::class) as $return) {
        if ($return->expr !== null) {
            $returns[] = $return->expr;
        }
    }

    return ['stmts' => $function->stmts, 'returns' => $returns];
}

function ctlLabel(string $body, int $returnIndex = 0): ?string
{
    $parsed = ctlParse($body);

    return ContentTypeLabel::of($parsed['stmts'], $parsed['returns'][$returnIndex]);
}

it('reads a Content-Type set on the returned variable', function (): void {
    expect(ctlLabel(<<<'PHP'
        $response = make();
        $response->headers->set('Content-Type', 'application/problem+json');
        return $response;
    PHP))->toBe('application/problem+json');
});

it('matches the header name case-insensitively', function (): void {
    expect(ctlLabel(<<<'PHP'
        $response = make();
        $response->headers->set('content-type', 'application/problem+json');
        return $response;
    PHP))->toBe('application/problem+json');
});

it('keeps the last label when the same branch overwrites it', function (): void {
    expect(ctlLabel(<<<'PHP'
        $response = make();
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Type', 'application/ld+json');
        return $response;
    PHP))->toBe('application/ld+json');
});

it('does not attribute a later branch\'s header to an earlier branch sharing the variable name', function (): void {
    // The idiomatic shape: both branches call their response `$response`. The first return carries no
    // label of its own and must stay unlabelled.
    $body = <<<'PHP'
        if ($flag) {
            $response = make();
            return $response;
        }

        $response = make();
        $response->headers->set('Content-Type', 'application/problem+json');
        return $response;
    PHP;

    expect(ctlLabel($body, 0))->toBeNull();
    expect(ctlLabel($body, 1))->toBe('application/problem+json');
});

it('does not attribute an earlier branch\'s header to a later branch sharing the variable name', function (): void {
    $body = <<<'PHP'
        if ($flag) {
            $response = make();
            $response->headers->set('Content-Type', 'application/problem+json');
            return $response;
        }

        $response = make();
        return $response;
    PHP;

    expect(ctlLabel($body, 0))->toBe('application/problem+json');
    expect(ctlLabel($body, 1))->toBeNull();
});

it('reads a header written before the variable was ever assigned as belonging to nothing', function (): void {
    // No assignment at all (the variable is a parameter, say): everything before the return is in scope,
    // which is the widest the window ever gets.
    expect(ctlLabel(<<<'PHP'
        $response->headers->set('Content-Type', 'application/problem+json');
        return $response;
    PHP))->toBe('application/problem+json');
});

it('declines anything it cannot read as a literal label', function (string $body): void {
    expect(ctlLabel($body))->toBeNull();
})->with([
    'non-variable return' => ['return make()->withHeaders();'],
    'a different variable' => ["\$other = make();\n\$other->headers->set('Content-Type', 'text/csv');\n\$response = make();\nreturn \$response;"],
    'a different header' => ["\$response = make();\n\$response->headers->set('X-Trace', 'abc');\nreturn \$response;"],
    'a computed header name' => ["\$response = make();\n\$response->headers->set(\$name, 'text/csv');\nreturn \$response;"],
    'a computed value' => ["\$response = make();\n\$response->headers->set('Content-Type', \$type);\nreturn \$response;"],
    'not the headers bag' => ["\$response = make();\n\$response->attributes->set('Content-Type', 'text/csv');\nreturn \$response;"],
    'a computed bag name' => ["\$response = make();\n\$response->{\$bag}->set('Content-Type', 'text/csv');\nreturn \$response;"],
    'not set()' => ["\$response = make();\n\$response->headers->replace('Content-Type', 'text/csv');\nreturn \$response;"],
    'a bag on a non-variable' => ["\$response = make();\nmake()->headers->set('Content-Type', 'text/csv');\nreturn \$response;"],
    'a variable-variable return' => ['return $$name;'],
]);
