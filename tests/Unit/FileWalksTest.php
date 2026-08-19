<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;
use PhpParser\Node;
use PHPStan\Analyser\MutatingScope;
use PHPStan\Analyser\Scope;

/**
 * In-process mechanics cover for {@see FileWalks}: how many live passes it spends, what a replay hands over
 * and in what order, and every path that declines to serve one. Whether a REAL replayed walk answers types
 * the way the live pass that recorded it did is the fixture group's job (`ReplayParityTest`).
 */

/**
 * Each walked pair as `[node class, probe name, scope object id]` — enough to compare two walks node for
 * node, scope for scope, in order.
 *
 * @param  list<array{Node, Scope}>  $pairs
 * @return list<array{string, string, int}>
 */
function walkShape(array $pairs): array
{
    return array_map(
        static fn (array $pair): array => [
            $pair[0]::class,
            (string) $pair[0]->getAttribute('probe'),
            spl_object_id($pair[1]),
        ],
        $pairs,
    );
}

/**
 * @return list<array{Node, Scope}>
 */
function collectWalk(FileWalks $walks, string $file): array
{
    $seen = [];
    $walks->walk($file, static function (Node $node, Scope $scope) use (&$seen): void {
        $seen[] = [$node, $scope];
    });

    return $seen;
}

/** A node a walk-shape comparison can tell apart from its neighbours. */
function probeNode(string $name): Node\Expr\Variable
{
    $node = new Node\Expr\Variable($name);
    $node->setAttribute('probe', $name);

    return $node;
}

it('replays a recorded walk verbatim, on one live pass', function (): void {
    $scopeA = $this->createStub(Scope::class);
    $scopeB = $this->createStub(Scope::class);

    // The first two nodes share one scope instance, which is the shape the dedupe exists for.
    $adapter = new ScriptedRuntimeAdapter(['/a.php' => [
        [probeNode('one'), $scopeA],
        [probeNode('two'), $scopeA],
        [probeNode('three'), $scopeB],
    ]]);
    $walks = new FileWalks($adapter);

    $first = collectWalk($walks, '/a.php');
    $second = collectWalk($walks, '/a.php');
    $third = collectWalk($walks, '/a.php');

    // Same nodes, same scope objects, same order — a replay is indistinguishable from the walk that
    // recorded it, which is what makes the layer invisible to the emitted document.
    expect($first)->toHaveCount(3)
        ->and(walkShape($second))->toBe(walkShape($first))
        ->and(walkShape($third))->toBe(walkShape($first))
        ->and($adapter->passes)->toBe(['/a.php' => 1]);

    // And what was recorded is the STABILISED scope, not the one the pass handed out.
    expect($first[0][1])->not->toBe($scopeA)
        ->and($first[0][1])->toBe($adapter->stableScope($scopeA))
        ->and($first[1][1])->toBe($first[0][1])
        ->and($first[2][1])->toBe($adapter->stableScope($scopeB));
});

it('records each file separately', function (): void {
    $scope = $this->createStub(Scope::class);
    $adapter = new ScriptedRuntimeAdapter([
        '/a.php' => [[probeNode('a'), $scope]],
        '/b.php' => [[probeNode('b'), $scope]],
    ]);
    $walks = new FileWalks($adapter);

    foreach (['/a.php', '/b.php', '/a.php', '/b.php'] as $file) {
        collectWalk($walks, $file);
    }

    expect($adapter->passes)->toBe(['/a.php' => 1, '/b.php' => 1]);
});

it('replays a file whose pass emitted nothing', function (): void {
    // Nothing harvested is a real answer — an interface, a file of constants — and re-walking to hear it
    // again is exactly the cost this layer exists to stop paying.
    $adapter = new ScriptedRuntimeAdapter;
    $walks = new FileWalks($adapter);

    expect(collectWalk($walks, '/empty.php'))->toBe([])
        ->and(collectWalk($walks, '/empty.php'))->toBe([])
        ->and($adapter->passes)->toBe(['/empty.php' => 1]);
});

it('records nothing for a pass that blew up, so the next ask gets a live one', function (): void {
    // A truncated recording would answer a later consumer with less than a live pass gives it — the one
    // way this layer could change what a build says. Discarding is the honest fallback.
    $scope = $this->createStub(Scope::class);
    $adapter = new ScriptedRuntimeAdapter(['/a.php' => [[probeNode('a'), $scope]]]);
    $walks = new FileWalks($adapter);

    $attempt = static function () use ($walks): void {
        $walks->walk('/a.php', static function (): void {
            throw new RuntimeException('visitor blew up');
        });
    };

    expect($attempt)->toThrow(RuntimeException::class, 'visitor blew up')
        ->and($attempt)->toThrow(RuntimeException::class)
        ->and($adapter->passes)->toBe(['/a.php' => 2]);

    // Once a walk completes, the recording stands.
    collectWalk($walks, '/a.php');
    collectWalk($walks, '/a.php');
    expect($adapter->passes)->toBe(['/a.php' => 3]);
});

it('records nothing from inside another walk, whichever file is asked for', function (): void {
    // The guard is global, not per file: a re-entrant ask for ANOTHER file would otherwise build a
    // recording interleaved with the outer walk's nodes. Either way the inner ask gets a plain live pass,
    // and the outer recording still stands.
    $scope = $this->createStub(Scope::class);
    $adapter = new ScriptedRuntimeAdapter([
        '/a.php' => [[probeNode('a'), $scope]],
        '/b.php' => [[probeNode('b'), $scope]],
    ]);
    $walks = new FileWalks($adapter);

    $inner = [];
    $walks->walk('/a.php', function () use ($walks, &$inner): void {
        $inner = [...collectWalk($walks, '/a.php'), ...collectWalk($walks, '/b.php')];
    });

    expect($adapter->passes)->toBe(['/a.php' => 2, '/b.php' => 1])
        ->and($inner)->toHaveCount(2)
        // The inner passes stabilise too, so a re-entrant consumer sees exactly what a replay would.
        ->and($inner[0][1])->toBe($adapter->stableScope($scope));

    // Neither inner pass recorded: /a.php replays what the OUTER walk recorded, /b.php walks live again.
    collectWalk($walks, '/a.php');
    collectWalk($walks, '/b.php');
    expect($adapter->passes)->toBe(['/a.php' => 2, '/b.php' => 2]);
});

it('records a file of exactly the node budget', function (): void {
    // The boundary is inclusive: a file that fits is recorded, so the budget never costs a replay it
    // could have served.
    $scope = $this->createStub(Scope::class);
    $adapter = new ScriptedRuntimeAdapter(['/a.php' => [
        [probeNode('a1'), $scope],
        [probeNode('a2'), $scope],
    ]]);
    $walks = new FileWalks($adapter, nodeBudget: 2);

    $live = collectWalk($walks, '/a.php');
    $replayed = collectWalk($walks, '/a.php');

    expect($adapter->passes)->toBe(['/a.php' => 1])
        ->and(walkShape($replayed))->toBe(walkShape($live));
});

it('abandons a file over the node budget mid-pass and remembers it, accumulating nothing again', function (): void {
    // Two guarantees in one: nothing is retained for a file that could never fit (so its peak cost is the
    // pass itself, not the recording it would have thrown away), and the verdict is remembered — the second
    // ask goes straight to a live pass rather than re-paying the accumulation.
    $scope = $this->createStub(Scope::class);
    $adapter = new ScriptedRuntimeAdapter([
        '/small.php' => [[probeNode('a'), $scope]],
        '/huge.php' => [[probeNode('b'), $scope], [probeNode('c'), $scope], [probeNode('d'), $scope]],
    ]);
    $walks = new FileWalks($adapter, nodeBudget: 2);

    collectWalk($walks, '/small.php');
    $firstHuge = collectWalk($walks, '/huge.php');
    $secondHuge = collectWalk($walks, '/huge.php');
    collectWalk($walks, '/small.php');

    // /huge.php is live both times and /small.php's recording survived it — nothing was evicted for a file
    // that was never going to be kept.
    expect($adapter->passes)->toBe(['/small.php' => 1, '/huge.php' => 2])
        // Abandoning changes the cost, never the answer: both live walks hand over the same nodes.
        ->and(walkShape($secondHuge))->toBe(walkShape($firstHuge))
        ->and($firstHuge)->toHaveCount(3);
});

it('abandons recording once memory usage reaches the ceiling', function (): void {
    // The node count is a proxy; bytes are the real risk, so a process near its limit stops recording even
    // for a file well inside the node budget. A ceiling of 1 byte is "already there".
    $scope = $this->createStub(Scope::class);
    $adapter = new ScriptedRuntimeAdapter(['/a.php' => [[probeNode('a'), $scope], [probeNode('b'), $scope]]]);
    $walks = new FileWalks($adapter, nodeBudget: 1000, memoryCeiling: 1);

    $first = collectWalk($walks, '/a.php');
    collectWalk($walks, '/a.php');

    // Nothing recorded, so both asks are live — and the walk still delivered every node.
    expect($adapter->passes)->toBe(['/a.php' => 2])
        ->and($first)->toHaveCount(2);
});

it('clears the whole store once the retained nodes would exceed the budget', function (): void {
    // Clearing rather than evicting one file at a time: a cleared file is walked live and re-recorded, and
    // replies identically — which is what licenses the cheaper reset.
    $scope = $this->createStub(Scope::class);
    $adapter = new ScriptedRuntimeAdapter([
        '/a.php' => [[probeNode('a1'), $scope], [probeNode('a2'), $scope]],
        '/b.php' => [[probeNode('b1'), $scope], [probeNode('b2'), $scope]],
        '/c.php' => [[probeNode('c1'), $scope], [probeNode('c2'), $scope]],
    ]);
    $walks = new FileWalks($adapter, nodeBudget: 4);

    $liveA = collectWalk($walks, '/a.php');
    collectWalk($walks, '/b.php');             // the two of them fill the budget exactly
    collectWalk($walks, '/a.php');             // still a replay
    collectWalk($walks, '/c.php');             // over budget ⇒ the store is cleared, then /c.php recorded
    $relivedA = collectWalk($walks, '/a.php'); // cleared, so live again — and re-recorded
    collectWalk($walks, '/a.php');             // the re-recording serves this one
    collectWalk($walks, '/b.php');             // cleared too, so live again

    expect($adapter->passes)->toBe(['/a.php' => 2, '/b.php' => 2, '/c.php' => 1])
        // A relived walk is the same walk: the clear cost a pass and changed no answer.
        ->and(walkShape($relivedA))->toBe(walkShape($liveA));
});

it('discards a recording made before the analysed set grew', function (): void {
    // PHPStan gates trait inlining on the analysed-file set, so a walk recorded before an unrelated file was
    // primed can answer with less than a live pass would now. Without this, one route's richness would
    // depend on which unrelated route ran first.
    $scope = $this->createStub(Scope::class);
    $adapter = new ScriptedRuntimeAdapter(['/a.php' => [[probeNode('a'), $scope]]], analysedFiles: 10);
    $walks = new FileWalks($adapter);

    collectWalk($walks, '/a.php');
    collectWalk($walks, '/a.php');
    expect($adapter->passes)->toBe(['/a.php' => 1]);

    $adapter->analysedFiles = 11;
    collectWalk($walks, '/a.php');   // stale ⇒ discarded and re-recorded at the new size
    collectWalk($walks, '/a.php');   // the re-recording is current, so this replays

    expect($adapter->passes)->toBe(['/a.php' => 2]);
});

it('reads every memory_limit shorthand, and no ceiling from one it cannot', function (string $limit, ?int $expected): void {
    // Every suffix php.ini accepts, plus the values that are not a ceiling at all. Read through reflection
    // because it is a private static: what a test can otherwise observe is only "recording stopped", which
    // cannot tell a misparsed suffix from a real ceiling. Driving it through `ini_set` would not reach the
    // unreadable rows either — PHP rejects a memory_limit it cannot parse, so those are what this guard is
    // FOR: no ceiling to compare against, leaving the node budget to bound alone. Vaguer, never wrong.
    $reader = new ReflectionMethod(FileWalks::class, 'ceiling');

    expect($reader->invoke(null, $limit))->toBe($expected);
})->with([
    // 70% of each ceiling — the headroom a recording leaves the rest of the build.
    'bytes' => ['1000', 700],
    'kilobytes' => ['100K', 71680],
    'megabytes' => ['512M', 375809638],
    'gigabytes' => ['2G', 1503238553],
    'lower-case suffix, padded' => [' 512m ', 375809638],
    'unlimited' => ['-1', null],
    'nothing configured' => ['', null],
    'not a number' => ['lots', null],
    'a suffix php has no unit for' => ['64T', null],
    'too many digits to be a byte count' => [str_repeat('9', 19), null],
    'a figure that would overflow its scale' => [str_repeat('9', 18).'G', null],
]);

it('derives its default ceiling from the process memory_limit', function (): void {
    // The one thing the pure parse above cannot show: the default constructor really reads the ini, so a
    // build inherits the ceiling it runs under rather than a hard-coded one.
    $inForce = (new ReflectionMethod(FileWalks::class, 'ceiling'))->invoke(null, (string) ini_get('memory_limit'));
    $derived = (new ReflectionProperty(FileWalks::class, 'memoryCeiling'))->getValue(new FileWalks(new ScriptedRuntimeAdapter));

    expect($derived)->toBe($inForce);
});

it('never hands a fresh scope a dead scope stabilisation', function (): void {
    // The livePass() WeakMap, against the spl_object_id-keyed array it could have been. PHPStan drops scopes
    // as it walks and PHP recycles object handles, so a later scope really can arrive on a dead one's id;
    // reverting that map to `$stable[spl_object_id($scope)]` fails this test on the distinctness below.
    // Reproducing it needs scopes created and dropped DURING the pass, which a pre-built script cannot do.
    $reflection = new ReflectionClass(MutatingScope::class);
    $handles = [];

    $adapter = new ScriptedRuntimeAdapter(['/a.php' => function (callable $emit) use ($reflection, &$handles): void {
        foreach (['one', 'two', 'three'] as $name) {
            $scope = $reflection->newInstanceWithoutConstructor();
            $handles[] = spl_object_id($scope);
            $emit(probeNode($name), $scope);
            unset($scope);   // the pass retains nothing, exactly as PHPStan's does not
        }
    }]);

    $walked = collectWalk(new FileWalks($adapter), '/a.php');

    // The premise: at least one scope came back on a handle an earlier one had used. Asserted rather than
    // assumed, so a platform that stopped recycling handles fails here instead of passing vacuously.
    expect(count(array_unique($handles)))->toBeLessThan(3);

    // The guarantee: every node still got its OWN scope's stabilisation.
    $stabilised = array_map(static fn (array $pair): int => spl_object_id($pair[1]), $walked);
    expect($walked)->toHaveCount(3)
        ->and(array_unique($stabilised))->toHaveCount(3);
});
