<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Guards\ProbeGuardedQuery;
use Docuccino\Inference\PhpStan\Throwing\PrivateHelpers;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The guards a method keeps in private methods of its own class are code it writes for itself: no subclass
 * can replace one, so the status a `throw` there builds is the method's. A protected or public method may be
 * overridden, an inherited one is someone else's, and a call on another object or a name chosen at run time
 * names no method of this class — none of them is read.
 */
it('names the private methods of its own class a body calls on $this, self or static', function (): void {
    $file = (string) (new ReflectionClass(ProbeGuardedQuery::class))->getFileName();
    $run = (new NodeFinder)->findFirst(
        (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [],
        static fn ($node): bool => $node instanceof ClassMethod && $node->name->toString() === 'run',
    );
    assert($run instanceof ClassMethod);

    expect(PrivateHelpers::called(ProbeGuardedQuery::class, $run->stmts ?? []))->toBe(['guard', 'staticGuard'])
        ->and(PrivateHelpers::called('App\\Nope\\NoSuchQuery', $run->stmts ?? []))->toBe([]);
});
