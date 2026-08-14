<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Inference\PhpStan\Trace\ConstantFolder;
use Docuccino\Inference\PhpStan\Trace\TypeScopeImpl;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPUnit\Framework\MockObject\Stub;

/**
 * In-process coverage for the fold's parameter BINDINGS — the half that makes a return fold work — and for
 * its first-class-callable guard. A helper's `AllowedFilter::exact($key, $column)` names nothing on its own;
 * bound to what the call site passed, it names a filter. PHPStan is consulted only for the fall-through
 * tail, so a scope double covers it.
 */
function foldableExpr(string $code): Node\Expr
{
    $stmts = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';');
    $stmt = $stmts[0] ?? null;

    return $stmt instanceof Node\Stmt\Expression
        ? $stmt->expr
        : throw new RuntimeException('not an expression statement: '.$code);
}

/**
 * Resolves names verbatim and folds string literals — enough for the fall-through tail, the only place a
 * real scope is consulted. `createStub()` is protected on the test case, so the caller makes the stub and
 * this arms it.
 */
function foldingScope(Stub&Scope $scope): Scope
{
    $scope->method('resolveName')->willReturnCallback(
        static fn (Node\Name $name): string => $name->toString(),
    );
    $scope->method('getType')->willReturnCallback(
        static fn (Node\Expr $expr): Type => $expr instanceof Node\Scalar\String_
            ? new ConstantStringType($expr->value)
            : new MixedType,
    );

    return $scope;
}

it('binds a call-site argument to the parameter a helper body reads', function (): void {
    $value = ConstantFolder::fold(
        foldableExpr('AllowedFilter::exact($key, $column)'),
        foldingScope($this->createStub(Scope::class)),
        ['key' => ConstValue::scalar('status'), 'column' => ConstValue::scalar('status_code')],
    );

    expect($value?->render())->toBe("AllowedFilter::exact('status', 'status_code')");
});

it('binds through an array literal and a chained call, so a helper returning several entries folds', function (): void {
    $value = ConstantFolder::fold(
        foldableExpr('[AllowedFilter::exact($key)->default($fallback)]'),
        foldingScope($this->createStub(Scope::class)),
        ['key' => ConstValue::scalar('status'), 'fallback' => ConstValue::scalar('open')],
    );

    expect($value?->render())->toBe("[AllowedFilter::exact('status')->default('open')]");
});

it('leaves an unbound parameter unknown rather than guessing at it', function (): void {
    // The honest outcome for an argument the call site could not fold: the entry names nothing and the
    // visitor degrades it to a diagnostic.
    $value = ConstantFolder::fold(
        foldableExpr('AllowedFilter::exact($key)'),
        foldingScope($this->createStub(Scope::class)),
    );

    expect($value?->render())->toBe('AllowedFilter::exact(<unknown: non-constant factory arg>)');
});

it('binds a parameter read anywhere the value can appear, including inside a nested constructor', function (): void {
    $value = ConstantFolder::fold(
        foldableExpr('AllowedFilter::custom($key, new SearchFilter($column))'),
        foldingScope($this->createStub(Scope::class)),
        ['key' => ConstValue::scalar('q'), 'column' => ConstValue::scalar('title')],
    );

    expect($value?->render())->toBe("AllowedFilter::custom('q', new SearchFilter('title'))");
});

it('folds an ordinary factory call and its chain to a descriptor', function (): void {
    // The positive control: the first-class-callable guard must not disarm cases 2, 3 or 5.
    $scope = foldingScope($this->createStub(Scope::class));

    expect(ConstantFolder::fold(foldableExpr("AllowedFilter::exact('tag')"), $scope)?->render())
        ->toBe("AllowedFilter::exact('tag')")
        ->and(ConstantFolder::fold(foldableExpr("Rule::in('a')->only('b')"), $scope)?->render())
        ->toBe("Rule::in('a')->only('b')")
        ->and(ConstantFolder::fold(foldableExpr("new Enum('a')"), $scope)?->render())
        ->toBe("new Enum('a')");
});

it('declines a first-class callable rather than asserting inside getArgs()', function (string $code): void {
    // php-parser asserts `! isFirstClassCallable()` inside `getArgs()`, so an unguarded fold throws under
    // the dev default `zend.assertions=1` — and the Tracer swallows it, silently truncating the trace.
    // `new X(...)` is a compile error for PHP itself, but php-parser accepts it and PHPStan analyses the
    // file (reporting callable.notSupported), so a visitor can still be handed the node.
    expect(ConstantFolder::fold(foldableExpr($code), foldingScope($this->createStub(Scope::class))))->toBeNull();
})->with([
    'static call' => ['Tags::filter(...)'],
    'chained call over a descriptor receiver' => ["Rule::in('a')->only(...)"],
    'new-expression' => ['new Enum(...)'],
]);

it('keeps a first-class-callable argument as an unresolved entry, not a fabricated one', function (): void {
    // The reported shape. An empty-arg descriptor here would name a call the code never makes, so the
    // arg has to read as unknown — the whole allow-list entry used to vanish instead.
    $value = ConstantFolder::fold(
        foldableExpr("AllowedFilter::callback('tag', Tags::filter(...))"),
        foldingScope($this->createStub(Scope::class)),
    );

    expect($value?->render())->toBe("AllowedFilter::callback('tag', <unknown: non-constant factory arg>)");
});

it('reaches the fold through the TypeScope a visitor is handed, and folds no call returns without a deferrer', function (): void {
    $scope = new TypeScopeImpl(foldingScope($this->createStub(Scope::class)), new TypeTranslator);

    expect($scope->constantValueOf(foldableExpr("AllowedFilter::exact('tag')"))?->render())
        ->toBe("AllowedFilter::exact('tag')")
        ->and($scope->deferReturnFold(foldableExpr('$this->termFilter()'), static function (): void {}))
        ->toBeFalse();
});

it('hands a deferred fold the call and the callback, and answers with the queue', function (): void {
    $seen = null;
    $scope = new TypeScopeImpl(
        foldingScope($this->createStub(Scope::class)),
        new TypeTranslator,
        function (Node\Expr $call, callable $onFolded) use (&$seen): bool {
            $seen = $call;
            $onFolded(ConstValue::scalar('status'), null);

            return true;
        },
    );

    $call = foldableExpr('$this->termFilter()');
    $folded = null;

    expect($scope->deferReturnFold($call, function (?ConstValue $value) use (&$folded): void {
        $folded = $value;
    }))->toBeTrue()
        ->and($seen)->toBe($call)
        ->and($folded?->render())->toBe("'status'");
});
