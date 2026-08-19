<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\ShouldNotHappenException;
use PHPUnit\Framework\MockObject\Stub;

/**
 * In-process mechanics coverage for {@see FileAnalyzer}: the harvest/collection is driven by a
 * controllable adapter (the node WATCHING itself — pairing returns/closures/assignments with scope — is
 * real-engine behaviour, proven by the --group=fixture suites). Here a no-emit adapter exercises the
 * memoisation and empty-collection paths deterministically.
 */
function fileAnalyzerOn(ScriptedRuntimeAdapter $adapter): FileAnalyzer
{
    return new FileAnalyzer($adapter, new FileWalks($adapter));
}

/**
 * An analyzer over a scripted `[node, scope]` pass, so the harvest callback can be driven over the shapes
 * the write half reads without a container to resolve them.
 *
 * @param  list<array{Node, Scope}>  $nodes
 */
function fileAnalyzerOverNodes(array $nodes): FileAnalyzer
{
    return fileAnalyzerOn(new ScriptedRuntimeAdapter(['/x.php' => $nodes]));
}

/** The expression `<code>` parses to, so a test names the shape it drives as the PHP it stands for. */
function fileAnalyzerExpr(string $code): Node\Expr
{
    $stmts = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';');
    $stmt = $stmts[0] ?? null;

    return $stmt instanceof Node\Stmt\Expression
        ? $stmt->expr
        : throw new RuntimeException('not an expression statement: '.$code);
}

/**
 * A scope in `$function` whose every type resolution throws the way PHPStan does when it cannot answer.
 * `createStub()` is protected on the test case, so the caller makes the stub and this arms it.
 */
function fileAnalyzerFailingScope(Stub&Scope $scope, string $function): Scope
{
    $scope->method('getFunctionName')->willReturn($function);
    $scope->method('getType')->willThrowException(new ShouldNotHappenException('cannot resolve'));
    $scope->method('getMethodReflection')->willThrowException(new ShouldNotHappenException('cannot resolve'));

    return $scope;
}

it('harvests methods, closures and assignments off one pass per normalised file', function (): void {
    $adapter = new ScriptedRuntimeAdapter;
    $analyzer = fileAnalyzerOn($adapter);

    // Whichever harvest is asked for first pays the one pass; the no-emit adapter collects nothing.
    expect($analyzer->analyze('/x.php'))->toBe([])
        ->and($analyzer->closures('/x.php'))->toBe([])
        ->and($analyzer->arrayAssignments('/x.php'))->toBe([])
        ->and($analyzer->localAssignments('/x.php'))->toBe([])
        ->and($adapter->totalPasses)->toBe(1);

    // Re-access hits the per-file cache — no further passes.
    expect($analyzer->analyze('/x.php'))->toBe([])
        ->and($analyzer->closures('/x.php'))->toBe([])
        ->and($analyzer->arrayAssignments('/x.php'))->toBe([])
        ->and($analyzer->localAssignments('/x.php'))->toBe([])
        ->and($adapter->totalPasses)->toBe(1);
});

it('answers for no method the file does not declare', function (): void {
    // The lookup a caller with a class in hand makes, and the by-name one a closure-based caller makes.
    // A file declaring nothing answers neither — which is the `inference.method-not-found` degradation,
    // not a body borrowed from somewhere else.
    $adapter = new ScriptedRuntimeAdapter;
    $analyzer = fileAnalyzerOn($adapter);

    expect($analyzer->method('/x.php', 'App\\Renderer', 'render'))->toBeNull()
        ->and($analyzer->method('/x.php', null, 'render'))->toBeNull()
        // Both went through the one memoised harvest.
        ->and($adapter->totalPasses)->toBe(1);
});

it('retires only the failing scope when the write harvest throws mid-walk', function (): void {
    // A real MethodReturnStatementsNode needs a container to exist, so what this pins is the other half of
    // the guarantee: the walk that feeds the method and closure harvests runs to its end, and the harvest
    // the methods reader calls returns instead of throwing.
    $analyzer = fileAnalyzerOverNodes([
        [fileAnalyzerExpr('$body = [1]'), fileAnalyzerFailingScope($this->createStub(Scope::class), 'render')],
        // Resolving this callee is where PHPStan throws — one `$body` argument gets past the pre-scan.
        [fileAnalyzerExpr('$this->helper->fill($body)'), fileAnalyzerFailingScope($this->createStub(Scope::class), 'render')],
        [fileAnalyzerExpr('$kept = [2]'), fileAnalyzerFailingScope($this->createStub(Scope::class), 'other')],
    ]);

    expect($analyzer->analyze('/x.php'))->toBe([])
        ->and($analyzer->closures('/x.php'))->toBe([])
        // Vague but true: the scope whose writes could not be read keeps no local worth folding — the entry
        // is present and retired, the same answer a second write to `$body` would have left.
        ->and($analyzer->localAssignments('/x.php')['render'] ?? null)->toBe(['body' => null])
        // The nodes after the failure were still walked, so an unrelated scope answers as before.
        ->and($analyzer->localAssignments('/x.php')['other']['kept'] ?? null)->not->toBeNull()
        // Array initialisers are provenance only, so they stand — as they do for any other retired local.
        ->and($analyzer->arrayAssignments('/x.php')['render']['body'] ?? null)->toBeInstanceOf(Node\Expr\Array_::class);
});

it('resolves no callee for a call that passes no plain variable', function (): void {
    // The pre-scan: nothing a by-reference parameter could bind to, so the callee is never resolved — which
    // a scope that throws on every resolution is what proves. The assignment therefore stands.
    $analyzer = fileAnalyzerOverNodes([
        [fileAnalyzerExpr('$body = [1]'), fileAnalyzerFailingScope($this->createStub(Scope::class), 'render')],
        [fileAnalyzerExpr('$this->helper->fill([2], ...$rest)'), fileAnalyzerFailingScope($this->createStub(Scope::class), 'render')],
        [fileAnalyzerExpr('$this->helper->fill(...)'), fileAnalyzerFailingScope($this->createStub(Scope::class), 'render')],
    ]);

    expect($analyzer->localAssignments('/x.php')['render']['body'] ?? null)->not->toBeNull();
});
