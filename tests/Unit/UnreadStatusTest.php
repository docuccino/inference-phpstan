<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Inference\PhpStan\Throwing\ThrowAnalyzer;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatus;
use Docuccino\Inference\PhpStan\Throwing\UnreadStatusReason;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

/**
 * The pairing that keeps "the document published an unplaced status" and "the build had something to
 * say about it" from ever coming apart, and the table of reasons the second half is written from.
 */
it('gives every reason a sentence, and a remedy exactly where a reader owns one', function (UnreadStatusReason $reason, bool $hasRemedy): void {
    expect($reason->because())->not->toBe('')
        // The clause completes "…could not be read: <because>", so it is a lower-case fragment rather
        // than a sentence of its own.
        ->and($reason->because())->toMatch('/^[a-z]/')
        ->and($reason->remedy() !== null)->toBe($hasRemedy);

    if ($hasRemedy) {
        expect((string) $reason->remedy())->toEndWith('.');
    }
})->with([
    'a status argument that would not fold' => [UnreadStatusReason::DynamicArgument, true],
    'a construction that would not fold' => [UnreadStatusReason::DynamicConstruction, true],
    'a class that states no single status' => [UnreadStatusReason::UnstatedByClass, true],
    // The one whose remedy would be an edit to code the reader does not own, which is the whole
    // reason a reason carries one at all.
    'a class declared outside the project' => [UnreadStatusReason::ForeignClass, false],
]);

/**
 * A dataset only proves the rows it lists, so the set itself is held against the enum: a reason added
 * with no row would otherwise ship with its sentence unread by any test.
 */
it('answers for every reason there is', function (): void {
    $listed = [
        UnreadStatusReason::DynamicArgument,
        UnreadStatusReason::DynamicConstruction,
        UnreadStatusReason::UnstatedByClass,
        UnreadStatusReason::ForeignClass,
    ];

    expect(UnreadStatusReason::cases())->toBe($listed);
});

it('reads actionability off the file the fold read, never off the exception class', function (): void {
    $site = new SourceLocation('/app/Http/Controllers/ExportController.php', 18);

    // `abort($status)` raises the FRAMEWORK's own exception, and the expression that would not fold is
    // the application's own line. A test keyed on where the class is declared calls this unactionable.
    $abort = new UnreadStatus(
        'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
        UnreadStatusReason::DynamicArgument,
        $site,
        inProjectCode: true,
    );

    // The same reason in a file nobody here can edit — a package-shipped action — names an edit the
    // reader does not own.
    $shipped = new UnreadStatus(
        'Symfony\\Component\\HttpKernel\\Exception\\HttpException',
        UnreadStatusReason::DynamicArgument,
        $site,
        inProjectCode: false,
    );

    // And the gate's other conjunct, executed rather than assumed: a reason with no remedy is silent
    // whatever file it was read in. The analyser never builds this pairing — `ForeignClass` is recorded
    // only where the class is foreign, and with `inProjectCode` false in the same breath — so this is the
    // row that says a future producer could not report one by getting the flag wrong.
    $foreign = new UnreadStatus(
        'Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException',
        UnreadStatusReason::ForeignClass,
        $site,
        inProjectCode: true,
    );

    expect($abort->isActionable())->toBeTrue()
        ->and($shipped->isActionable())->toBeFalse()
        ->and($foreign->isActionable())->toBeFalse();
});

it('names the exception, the site and the fold that gave up, in one sentence', function (): void {
    $unread = new UnreadStatus(
        'App\\Exceptions\\ExportConflictException',
        UnreadStatusReason::DynamicConstruction,
        new SourceLocation('/app/Services/ExportProbeQuery.php', 22),
        inProjectCode: true,
    );

    expect($unread->sentence())
        ->toContain('App\\Exceptions\\ExportConflictException')
        ->toContain('/app/Services/ExportProbeQuery.php:22')
        ->toContain(UnreadStatusReason::DynamicConstruction->because());
});

it('keys one notice per exception, reason and site rather than per path that reaches it', function (): void {
    $at = static fn (int $line, UnreadStatusReason $reason): UnreadStatus => new UnreadStatus(
        'App\\Exceptions\\ExportConflictException',
        $reason,
        new SourceLocation('/app/Services/ExportProbeQuery.php', $line),
        inProjectCode: true,
    );

    expect($at(22, UnreadStatusReason::DynamicConstruction)->key())
        ->toBe($at(22, UnreadStatusReason::DynamicConstruction)->key())
        ->and($at(22, UnreadStatusReason::DynamicConstruction)->key())
        ->not->toBe($at(31, UnreadStatusReason::DynamicConstruction)->key())
        ->and($at(22, UnreadStatusReason::DynamicConstruction)->key())
        ->not->toBe($at(22, UnreadStatusReason::UnstatedByClass)->key());
});

/**
 * The invariant executed rather than asserted, and at the level it actually has to hold: the ANALYSIS
 * may not hand back "no status" with nothing to report. {@see ThrowAnalyzer::unread()} takes the
 * record's PARTS, so filing is the only way to build one and a record built-and-dropped is not
 * expressible; what is left to guard is a bare `null` written where a status belongs.
 *
 * Both halves are DERIVED from the source rather than listed, because a list only ever proves its own
 * rows — a third terminal status decision was added once and the listed two stayed green over it. The
 * sink is every `ThrownException` the package builds, which is where a missing status stops being an
 * internal answer and becomes a response the document publishes. The producers are every method whose
 * declared type admits a missing status, and each owes a ROW saying which kind it is, so a new one
 * fails here until somebody classifies it rather than falling in the gap between the two guards.
 */
it('produces a missing status in one place only, and files a reason there', function (): void {
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;
    $src = dirname(__DIR__, 2).'/src';

    $sources = [];
    /** @var SplFileInfo $entry */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS)) as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $sources[$entry->getPathname()] = $parser->parse((string) file_get_contents($entry->getPathname())) ?? [];
        }
    }
    ksort($sources);

    // A walk that stopped finding the package would agree with everything below.
    expect(count($sources))->toBeGreaterThan(20);

    // Every branch an expression can answer with, so a `null` tucked behind a `??`, a ternary or a
    // match arm is read as the answer it is.
    $branches = static function (Node\Expr $expr) use (&$branches): array {
        if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            return [...$branches($expr->left), ...$branches($expr->right)];
        }

        if ($expr instanceof Node\Expr\Ternary) {
            return [...($expr->if === null ? [] : $branches($expr->if)), ...$branches($expr->else)];
        }

        if ($expr instanceof Node\Expr\Match_) {
            $arms = [];
            foreach ($expr->arms as $arm) {
                $arms = [...$arms, ...$branches($arm->body)];
            }

            return $arms;
        }

        return [$expr];
    };

    $isNull = static fn (?Node $node): bool => $node instanceof Node\Expr\ConstFetch
        && $node->name->toLowerString() === 'null';

    // The sink. `ThrownException::$httpStatusHint` is what the adapter keys its unplaced status off, so
    // this is the boundary an unread status crosses to become a response somebody has to explain.
    $statuses = [];
    $sinkFiles = [];
    foreach ($sources as $file => $ast) {
        foreach ($finder->find($ast, static fn (Node $node): bool => $node instanceof Node\Expr\New_
            && $node->class instanceof Node\Name
            && $node->class->toString() === 'ThrownException') as $construction) {
            /** @var Node\Expr\New_ $construction */
            $sinkFiles[$file] = true;
            foreach ($construction->getArgs() as $position => $argument) {
                if ($argument->name?->toString() === 'httpStatusHint' || ($argument->name === null && $position === 1)) {
                    $statuses = [...$statuses, ...$branches($argument->value)];
                }
            }
        }
    }

    // Zero would pass forever, and the sink is the whole subject of the file.
    expect($statuses)->not->toBeEmpty();

    foreach ($statuses as $status) {
        expect($isNull($status))->toBeFalse();
    }

    // Which also says WHERE the producers can be: nothing else in the package builds one, so the rows
    // below may be read off a single class without listing that as an assumption.
    $analyzer = $src.'/Throwing/ThrowAnalyzer.php';
    expect(array_keys($sinkFiles))->toBe([$analyzer]);

    // The producers, derived from what each method's declared type admits rather than from a list of
    // names: a scalar `?int`/`null`, or an array shape carrying a nullable `status`.
    $admitsMissing = static function (Node\Stmt\ClassMethod $method): bool {
        $type = $method->returnType;
        if ($type instanceof Node\Identifier && $type->toLowerString() === 'null') {
            return true;
        }

        if ($type instanceof Node\NullableType && (string) $type->type === 'int') {
            return true;
        }

        if ($type instanceof Node\UnionType) {
            $names = array_map(static fn (Node $part): string => strtolower((string) $part), $type->types);
            if (in_array('int', $names, true) && in_array('null', $names, true)) {
                return true;
            }
        }

        return preg_match('/status\s*:\s*(\?int|int\|null|null\|int)/', (string) $method->getDocComment()?->getText()) === 1;
    };

    $producers = [];
    foreach ($finder->find($sources[$analyzer], static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod) as $method) {
        /** @var Node\Stmt\ClassMethod $method */
        if ($admitsMissing($method)) {
            $producers[$method->name->toString()] = $method;
        }
    }
    ksort($producers);

    // Terminal: its answer IS the status the document carries, so a bare `null` there is a response
    // nothing recorded. Intermediate: its `null` says one read did not fold, and a terminal method
    // decides what that means — so it may write one, and may never be a status on its own.
    $rows = [
        'atThrowSite' => 'intermediate',
        'foldStatusArg' => 'intermediate',
        'httpStatus' => 'terminal',
        'statusForType' => 'terminal',
        'unread' => 'recorder',
    ];

    // The union against the domain: a method that starts admitting a missing status owes a row here.
    expect(array_keys($producers))->toBe(array_keys($rows));

    foreach ($rows as $name => $kind) {
        $method = $producers[$name];

        if ($kind === 'terminal') {
            // No `return null`, and no `null` in the `status` slot of an array shape one hands back.
            $written = [];
            foreach ($finder->find($method, static fn (Node $node): bool => $node instanceof Node\Stmt\Return_
                && $node->expr !== null) as $return) {
                /** @var Node\Stmt\Return_ $return */
                $written = [...$written, ...$branches($return->expr)];
            }

            foreach ($finder->find($method, static fn (Node $node): bool => $node instanceof Node\ArrayItem
                && $node->key instanceof Node\Scalar\String_
                && $node->key->value === 'status') as $item) {
                /** @var Node\ArrayItem $item */
                $written = [...$written, ...$branches($item->value)];
            }

            expect($written)->not->toBeEmpty();

            foreach ($written as $expression) {
                expect($isNull($expression))->toBeFalse();
            }
        }

        if ($kind === 'intermediate') {
            // The exemption expires the moment one is wired straight into a published status.
            foreach ($statuses as $status) {
                expect($status instanceof Node\Expr\MethodCall
                    && $status->name instanceof Node\Identifier
                    && $status->name->toString() === $name)->toBeFalse();
            }
        }
    }

    // And the recorder: the one place a record is built, which is what makes filing the only way to
    // make one at all.
    $built = [];
    foreach ($sources as $file => $ast) {
        foreach ($finder->find($ast, static fn (Node $node): bool => $node instanceof Node\Expr\New_
            && $node->class instanceof Node\Name
            && $node->class->toString() === 'UnreadStatus') as $construction) {
            $built[] = $file;
        }
    }

    expect($built)->toBe([$analyzer])
        ->and($finder->find($producers['unread'], static fn (Node $node): bool => $node instanceof Node\Expr\New_
            && $node->class instanceof Node\Name
            && $node->class->toString() === 'UnreadStatus'))->toHaveCount(1)
        ->and((string) (new ReflectionMethod(ThrowAnalyzer::class, 'unread'))->getReturnType())->toBe('null');
});
