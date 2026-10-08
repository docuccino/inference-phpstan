<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;
use PhpParser\Node;
use PhpParser\NodeFinder;
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

/** A source file of the test's own, named for it alone and removed when the process ends. */
function fileAnalyzerScratch(string $source): string
{
    $file = sys_get_temp_dir().'/docuccino-closure-lines-'.getmypid().'-'.md5($source).'.php';
    file_put_contents($file, $source);
    register_shutdown_function(static fn () => @unlink($file));

    return $file;
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

it('finds a closure at the line reflection gives it, and none where a line declares two', function (): void {
    // Reflection places a closure on its keyword, the parser on its attribute or `static`: the offset
    // answered is where the parser starts the ONE closure whose keyword is on the line asked about.
    $source = <<<'PHP'
        <?php
        return [
            'attributed' => #[\Deprecated]
                function () {},
            'static' => static
                fn () => 1,
            'nested' => fn () => fn () => 2,
        ];
        PHP;
    $file = fileAnalyzerScratch($source);
    /** @var array<string, Closure> $closures */
    $closures = require $file;
    $line = static fn (string $key): int => (int) (new ReflectionFunction($closures[$key]))->getStartLine();
    $analyzer = fileAnalyzerOn(new ScriptedRuntimeAdapter);

    expect($analyzer->closureAt($file, $line('attributed')))->toBe(strpos($source, '#[\Deprecated]'))
        ->and($analyzer->closureAt($file, $line('static')))->toBe(strpos($source, "static\n"))
        ->and($analyzer->closureAt($file, $line('nested')))->toBeNull()
        ->and($analyzer->closureAt($file, 1))->toBeNull()
        // Found in the source, but the pass harvested no body for it: nothing to answer with.
        ->and($analyzer->callableAtLine($file, $line('static')))->toBeNull()
        ->and($analyzer->callableAtLine($file, $line('nested')))->toBeNull();
});

it('finds no closure in a file it cannot read or parse', function (): void {
    $broken = fileAnalyzerScratch('<?php $f = function () {');
    $analyzer = fileAnalyzerOn(new ScriptedRuntimeAdapter);

    expect($analyzer->closureAt($broken, 1))->toBeNull()
        ->and($analyzer->closureAt($broken.'.missing', 1))->toBeNull();
});

it('holds a read only while nothing after it may have changed what it gives', function (string $code, string $read, string $variable, bool $member, bool $holds): void {
    $source = '<?php '.$code.';';
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $scope = fileAnalyzerFailingScope($this->createStub(Scope::class), 'render');
    $analyzer = fileAnalyzerOverNodes(array_map(
        static fn (Node $node): array => [$node, $scope],
        (new NodeFinder)->find($statements, static fn (Node $node): bool => true),
    ));
    $after = (int) strpos($source, $read) + strlen($read) - 1;

    expect($analyzer->readHolds('/x.php', 'render', $after, $variable, $member))->toBe($holds);
})->with([
    'the same member read again' => ['$s = $r->getStatusCode(); $t = $r->getStatusCode()', '$r->getStatusCode()', 'r', true, true],
    'other members read after it' => ['$s = $p->status(); $t = $p->title(); $u = $p?->value', '$p->status()', 'p', true, true],
    'a change made before the read' => ['$r->setStatusCode(503); $s = $r->getStatusCode()', '$r->getStatusCode()', 'r', true, true],
    'a call with arguments after it' => ['$s = $r->getStatusCode(); $r->setStatusCode(503)', '$r->getStatusCode()', 'r', true, false],
    'a nullsafe call with arguments after it' => ['$s = $r->getStatusCode(); $r?->setStatusCode(503)', '$r->getStatusCode()', 'r', true, false],
    'the object handed on after it' => ['$s = $r->getStatusCode(); handle($r)', '$r->getStatusCode()', 'r', true, false],
    'the property written after it' => ['$s = $r->status; $r->status = 503', '$r->status', 'r', true, false],
    'the property incremented after it' => ['$s = $r->status; $r->status++', '$r->status', 'r', true, false],
    'unset after it' => ['$s = $r->status; unset($r->status)', '$r->status', 'r', true, false],
    'the variable read again, or handed on' => ['$s = $code; $t = $code; handle($code)', '$s = $code', 'code', false, true],
    'the variable written after it' => ['$s = $code; $code = 503', '$s = $code', 'code', false, false],
    'an offset of it written after it' => ['$s = $code; $code[0] = 5', '$s = $code', 'code', false, false],
]);
