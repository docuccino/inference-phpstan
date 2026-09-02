<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\SourceOrder;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/*
 * The engine orders sites by where they appear in a file and lets the first one win — the return site a
 * refinement picks, and the callee a trace descends into. So the three things that matter here are that a
 * positioned node reports its BYTE offset (never its line, which would sort it among offsets it has
 * nothing to do with), that an unpositioned one sorts last rather than first, and that the links of a
 * CHAIN get distinct positions: php-parser reports the receiver's offset for every call in one chain, so
 * ordering by the node's own start would tie and leave the order to whoever handed the nodes over.
 */

it('reports a parsed node by byte offset, in source order', function (): void {
    $ast = (new ParserFactory)->createForHostVersion()->parse(<<<'PHP'
        <?php
        function handle() {
            return 1;
            return 22;
            return 333;
        }
        PHP) ?? [];

    /** @var list<Node\Stmt\Return_> $returns */
    $returns = (new NodeFinder)->findInstanceOf($ast, Node\Stmt\Return_::class);
    $positions = array_map(SourceOrder::of(...), $returns);

    expect($positions)->toHaveCount(3)
        // Byte offsets, not line numbers: the second `return` is many bytes in, on line 4.
        ->and($positions[0])->toBeGreaterThan($returns[0]->getStartLine())
        ->and($positions[0])->toBeLessThan($positions[1])
        ->and($positions[1])->toBeLessThan($positions[2]);
});

it('sorts a node with no position LAST, so it cannot displace one that has one', function (): void {
    // A hand-built node carries no file position at all. It feeds a first-wins selection, so putting it
    // first would let a site nothing can locate beat every site that can.
    $unpositioned = new Node\Scalar\String_('x');
    $positioned = new Node\Scalar\String_('y', ['startFilePos' => 40, 'endFilePos' => 42]);

    $nodes = [$unpositioned, $positioned];
    usort($nodes, static fn (Node $a, Node $b): int => SourceOrder::of($a) <=> SourceOrder::of($b));

    expect(SourceOrder::of($unpositioned))->toBe(PHP_INT_MAX)
        ->and(SourceOrder::of($positioned))->toBe(40)
        ->and($nodes[0])->toBe($positioned)
        ->and($nodes[1])->toBe($unpositioned);
});

it('positions each link of a chained call distinctly, left to right', function (): void {
    // `$a->b()->c()` and its inner `$a->b()` start at the same byte — the receiver's — so a sort over the
    // two calls a trace may descend into ties, and a tie is decided by PHPStan's node-callback order,
    // which is not source order and differs between analyser versions. Under a tight file budget that
    // decides WHICH of the two hops the walk can still afford, so the answer moved with the vendor tree.
    $ast = (new ParserFactory)->createForHostVersion()->parse(<<<'PHP'
        <?php
        function handle() {
            return (new Origin)->first()->second()->third();
        }
        PHP) ?? [];

    /** @var list<Node\Expr\MethodCall> $calls */
    $calls = (new NodeFinder)->findInstanceOf($ast, Node\Expr\MethodCall::class);
    $positions = array_map(SourceOrder::of(...), $calls);
    sort($positions);

    // Every link is at the same node start, and each gets its own position anyway.
    expect(array_unique(array_map(static fn (Node $call): int => $call->getStartFilePos(), $calls)))->toHaveCount(1)
        ->and($positions)->toHaveCount(3)
        ->and($positions)->toBe(array_values(array_unique($positions)));

    // And the order is the order the links are written, which is also the order they run.
    usort($calls, static fn (Node $a, Node $b): int => SourceOrder::of($a) <=> SourceOrder::of($b));

    expect(array_map(static fn (Node\Expr\MethodCall $call): string => (string) $call->name, $calls))
        ->toBe(['first', 'second', 'third']);
});

it('positions a static and a nullsafe chain the same way, since they tie the same way', function (
    string $code,
    array $expected,
): void {
    // Every chainable call form reports its receiver's offset, so each has its own tie: `A::b()::c()`
    // hangs a static call off a static call, and `$a?->b()?->c()` off a nullsafe one. The rows prove the
    // tie is real (one start offset for both links) and that the convention breaks it by the name.
    $ast = (new ParserFactory)->createForHostVersion()->parse($code) ?? [];

    /** @var list<Node\Expr\StaticCall|Node\Expr\NullsafeMethodCall> $calls */
    $calls = (new NodeFinder)->find($ast, static fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
        || $node instanceof Node\Expr\NullsafeMethodCall);

    expect(array_unique(array_map(static fn (Node $call): int => $call->getStartFilePos(), $calls)))->toHaveCount(1);

    usort($calls, static fn (Node $a, Node $b): int => SourceOrder::of($a) <=> SourceOrder::of($b));

    expect(array_map(static fn (Node\Expr\StaticCall|Node\Expr\NullsafeMethodCall $call): string => (string) $call->name, $calls))
        ->toBe($expected);
})->with([
    'a static call chained off a static call' => ['<?php function h() { return Origin::make()::only(); }', ['make', 'only']],
    'a nullsafe chain' => ['<?php function h() { return $origin?->first()?->second(); }', ['first', 'second']],
]);
