<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\CalleeCatches;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/*
 * Where a callee runs the callable it was handed, and what catches are around each place. Every place has to
 * be named, or the closure's throws are left to the caller's catches alone.
 */
it('names the catches around every place the callee calls the parameter', function (string $body, ?array $expected): void {
    $statements = (new NodeTraverser(new NameResolver))->traverse(
        (new ParserFactory)->createForHostVersion()->parse('<?php namespace App; use Other\B; function f(callable $work) { '.$body.' }') ?? [],
    );
    $function = (new NodeFinder)->findFirstInstanceOf($statements, Node\Stmt\Function_::class);
    assert($function instanceof Node\Stmt\Function_);

    expect(CalleeCatches::sites($function->stmts, 'work'))->toBe($expected);
})->with([
    'caught' => ['try { $work(); } catch (\Exception $e) {}', [['Exception']]],
    'caught, names resolved where written' => ['try { $work(); } catch (A|B $e) {}', [['App\A', 'Other\B']]],
    'not caught' => ['$work();', [[]]],
    'two places, each its own' => ['try { $work(); } catch (A) {} $work();', [['App\A'], []]],
    'rethrown' => ['try { $work(); } catch (A $e) { throw $e; }', [[]]],
    // Every way of reading the parameter that names no place it runs.
    'never called' => ['return 1;', null],
    'passed on' => ['try { g($work); } catch (A) {}', null],
    // …and the edit that turns a relay into a catch of its own changes the answer, which is why the file
    // that decided either is a dependency of both.
    'passed on, bare' => ['g($work);', null],
    'run under a catch with no variable' => ['try { $work(); } catch (\Exception) {}', [['Exception']]],
    // A catch that hands what it caught on takes nothing, whatever it hands it to.
    'handed to a call' => ['try { $work(); } catch (A $e) { report($e); }', [[]]],
    'handed to a method' => ['try { $work(); } catch (A $e) { $this->fail($e); }', [[]]],
    'read for its message' => ['try { $work(); } catch (A $e) { log($e->getMessage()); }', [['App\A']]],
    'passed on beside a call' => ['try { $work(); } catch (A) {} g($work);', null],
    'stored' => ['$this->later = $work;', null],
    'reassigned' => ['$work = fn () => 1; try { $work(); } catch (A) {}', null],
    'captured by a closure' => ['try { (function () use ($work) { $work(); })(); } catch (A) {}', null],
    'called through call_user_func' => ['try { call_user_func($work); } catch (A) {}', null],
    'read positionally' => ['try { $work(); func_get_args()[0](); } catch (A) {}', null],
    'read by a variable variable' => ['$n = "work"; try { $work(); $$n(); } catch (A) {}', null],
    'read by compact' => ['try { $work(); } catch (A) {} return compact("work");', null],
]);

it('binds an argument to its parameter by position or by name', function (string $call, ?string $expected): void {
    $statements = (new ParserFactory)->createForHostVersion()->parse('<?php function f(int $times, callable $work, &$ref = null, callable ...$rest) {} '.$call.';') ?? [];
    $function = (new NodeFinder)->findFirstInstanceOf($statements, Node\Stmt\Function_::class);
    $funcCall = (new NodeFinder)->findFirstInstanceOf($statements, Node\Expr\FuncCall::class);
    assert($function instanceof Node\Stmt\Function_ && $funcCall instanceof Node\Expr\FuncCall);

    $closure = 0;
    foreach ($funcCall->getArgs() as $position => $argument) {
        if ($argument->value instanceof Node\Expr\Closure) {
            $closure = $position;
        }
    }

    expect(CalleeCatches::parameter($function, $funcCall->getArgs()[$closure], $closure))->toBe($expected);
})->with([
    'by position' => ['f(3, function () {})', 'work'],
    'by name' => ['f(work: function () {}, times: 3)', 'work'],
    'by name, unknown' => ['f(3, nope: function () {})', null],
    'by reference' => ['f(3, fn () => 1, function () {})', null],
    'variadic' => ['f(3, fn () => 1, $r, function () {})', null],
    'past the last parameter' => ['f(3, fn () => 1, $r, fn () => 1, function () {})', null],
]);

it('reads a framework function by its contract: what its catch hands on keeps what it caught', function (): void {
    $statements = (new NodeTraverser(new NameResolver))->traverse(
        (new ParserFactory)->createForHostVersion()->parse('<?php function f(callable $work) { try { $work(); } catch (\Throwable $e) { report($e); return value(null, $e); } }') ?? [],
    );
    $function = (new NodeFinder)->findFirstInstanceOf($statements, Node\Stmt\Function_::class);
    assert($function instanceof Node\Stmt\Function_);

    expect(CalleeCatches::sites($function->stmts, 'work', byContract: true))->toBe([['Throwable']])
        ->and(CalleeCatches::sites($function->stmts, 'work'))->toBe([[]]);
});
