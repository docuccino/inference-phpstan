<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\SourceOrder;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/*
 * The engine orders sites by where they appear in a file and lets the first one win — the return site a
 * refinement picks, and the callee a trace descends into. So the two things that matter here are that a
 * positioned node reports its BYTE offset (never its line, which would sort it among offsets it has
 * nothing to do with) and that an unpositioned one sorts last rather than first.
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
