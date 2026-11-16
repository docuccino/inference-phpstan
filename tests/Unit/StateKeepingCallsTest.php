<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\StateKeepingCalls;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Keeping\Kept;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Keeping\KeptContract;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Keeping\KeptReader;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPUnit\Framework\MockObject\Stub;

/*
 * Whether a call leaves the object a variable holds as it was, read off the bodies it runs. A value read off
 * the object before the call is still what the object gives after it only where nothing the call runs writes
 * one of the object's own properties, so every way PHP writes one is a "no", and so is every way the object
 * can leave for somewhere this does not follow. Real reflection and real source, over probes written that way.
 */

/**
 * The call `$code` parses to, with the argument holding `$kept` (none where the call is made on it), asked in a
 * scope typing `$kept` as a {@see Kept}, `$contract` as a {@see KeptContract}, `$reader` as a {@see KeptReader}
 * and anything else as an int.
 *
 * @return array{bool, list<string>}
 */
function keptAt(StateKeepingCalls $keeping, Scope $scope, string $code): array
{
    $statement = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';')[0] ?? null;
    $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
    if (! $call instanceof Node\Expr\CallLike) {
        throw new RuntimeException('not a call: '.$code);
    }

    $argument = null;
    foreach ($call->isFirstClassCallable() ? [] : $call->getArgs() as $arg) {
        if ($arg->value instanceof Node\Expr\Variable && in_array($arg->value->name, ['kept', 'number'], true)) {
            $argument = $arg;
        }
    }

    return $keeping->atCall($call, $argument, $scope);
}

function keptScope(Scope&Stub $scope): Scope
{
    $scope->method('getType')->willReturnCallback(static fn (Node\Expr $expr): Type => match ($expr instanceof Node\Expr\Variable ? $expr->name : null) {
        'kept' => new ObjectType(Kept::class),
        'contract' => new ObjectType(KeptContract::class),
        'reader' => new ObjectType(KeptReader::class),
        default => new IntegerType,
    });
    $scope->method('resolveName')->willReturnCallback(static fn (Node\Name $name): string => $name->toString());

    return $scope;
}

it('keeps the object where nothing the call runs writes one of its own properties', function (string $code, bool $kept): void {
    expect(keptAt(new StateKeepingCalls, keptScope($this->createStub(Scope::class)), $code)[0])->toBe($kept);
})->with([
    'a property handed by value to a function' => ['$kept->decoded(true)', true],
    'a property counted' => ['$kept->counted(1)', true],
    'its class tested, a property read through another' => ['$kept->tested("why")', true],
    'its own methods followed, through `$this` and `self::`' => ['$kept->delegated(1)', true],
    'handed to a helper that only reads it' => ['$kept->handedOn(1)', true],
    'a method calling itself' => ['$kept->recursive(3)', true],
    'handed as an argument to a reader' => ['\\'.KeptReader::class.'::read($kept, 1)', true],
    'handed by name' => ['\\'.KeptReader::class.'::read(floor: 1, kept: $kept)', true],
    'handed to a reader on another object' => ['$reader->inspect($kept, 1)', true],
    // A setter handing the object back, `->header()` among them, returns what it was called on: nothing new
    // holds it where the caller drops that, or returns it to a caller that does.
    'a setter handing it back, what it returns dropped' => ['$kept->chainedDropped(1)', true],
    'handed to a helper calling such a setter' => ['\\'.KeptReader::class.'::stamp($kept, 1)', true],
    'handed to a helper returning what such a setter returns, dropped' => ['\\'.KeptReader::class.'::stampOnward($kept, 1)', true],

    'a property assigned' => ['$kept->restated(503)', false],
    'a property assigned through an operator' => ['$kept->bumped(1)', false],
    'a property incremented' => ['$kept->incremented(1)', false],
    'an offset of a property unset' => ['$kept->cleared(0)', false],
    'a property destructured into' => ['$kept->destructured([1, "a"])', false],
    'a reference taken to a property' => ['$kept->referenced(1)', false],
    'a property iterated by reference' => ['$kept->looped(1)', false],
    'a property a loop writes into' => ['$kept->loopedInto([1])', false],
    'a property handed to a by-reference parameter' => ['$kept->sorted(0)', false],
    'a property handed to a callee nothing names' => ['$kept->handedProperty($other)', false],
    // What the call made in analysed code returns is that code's own to keep, which is not followed.
    'the object returned' => ['$kept->returned(1)', false],
    'a setter handing it back, what it returns held and changed' => ['$kept->chainedKept(503)', false],
    'the object returned from a closure the call makes' => ['$kept->returnedLater(1)', false],
    'the object assigned to another variable' => ['$kept->aliased(1)', false],
    'the object handed to a function' => ['$kept->encoded(0)', false],
    'a method of it made a callable' => ['$kept->callable(1)', false],
    'a method of its own that writes, followed' => ['$kept->delegatedChange(503)', false],
    'a parent method that writes' => ['$kept->parentChange(503)', false],
    'handed to a helper that writes it' => ['$kept->handedToWriter(503)', false],
    'a chain of calls deeper than it follows' => ['$kept->deep(1)', false],
    'handed to a writer' => ['\\'.KeptReader::class.'::write($kept, 503)', false],
    'handed to a by-reference parameter' => ['\\'.KeptReader::class.'::swap($kept)', false],
    'handed to a function' => ['handle($kept)', false],
    'a method only `__call` answers' => ['$kept->undeclared(1)', false],
    'a method with no body' => ['$contract->status(1)', false],
    'a callable made at the call' => ['$kept->decoded(...)', false],
    'a value that is no object' => ['\\'.KeptReader::class.'::read($number, 1)', false],
]);

it('records every file the answer was read out of, a no as well, each time it is asked', function (): void {
    $keeping = new StateKeepingCalls;
    $scope = keptScope($this->createStub(Scope::class));
    $files = static fn (array $answer): array => array_map(basename(...), $answer[1]);

    // An edit to the helper can turn its write into a read: the "no" rests on it as much as a "yes" would.
    $writer = '\\'.KeptReader::class.'::write($kept, 503)';
    expect($files(keptAt($keeping, $scope, $writer)))->toBe(['KeptReader.php'])
        ->and($files(keptAt($keeping, $scope, $writer)))->toBe(['KeptReader.php'])
        // Which `counted()` the helper runs is the hierarchy's to say, so the parent's file keys it too.
        ->and($files(keptAt($keeping, $scope, '$kept->handedOn(1)')))->toBe(['Kept.php', 'KeptBase.php', 'KeptReader.php']);
});

/**
 * A class of helpers handing a {@see Kept} on in five layers of `$width`, each to every helper of the next, the
 * last only testing its class; written to a temp file named for the process and the width, kept until the
 * process ends. Its name.
 */
function keptLayers(int $width): string
{
    $namespace = 'Docuccino\\Inference\\PhpStan\\Tests\\Runtime\\Layers'.$width;
    $source = "<?php\nnamespace {$namespace};\nuse ".Kept::class.";\nfinal class Helpers {\n";
    for ($layer = 0; $layer < 5; $layer++) {
        for ($helper = 0; $helper < $width; $helper++) {
            $source .= "    public static function h{$layer}_{$helper}(Kept \$kept): void {\n";
            for ($next = 0; $layer < 4 && $next < $width; $next++) {
                $source .= '        self::h'.($layer + 1)."_{$next}(\$kept);\n";
            }
            $source .= $layer === 4 ? "        if (\$kept instanceof Kept) { return; }\n    }\n" : "    }\n";
        }
    }

    $file = sys_get_temp_dir().'/docuccino-kept-layers-'.getmypid().'-'.$width.'.php';
    file_put_contents($file, $source."}\n");
    // Kept until the process ends: the reader under test parses the class's file.
    register_shutdown_function(static fn () => @unlink($file));
    require_once $file;

    return $namespace.'\\Helpers';
}

it('reads each helper once for each depth it is reached at, however many paths reach it', function (): void {
    // Twelve helpers to a layer: 12^4 paths reach the last layer, but there are only sixty helpers to read.
    // Reading one once per path took over a minute here.
    $helpers = keptLayers(12);

    $started = hrtime(true);
    $kept = keptAt(new StateKeepingCalls, keptScope($this->createStub(Scope::class)), '\\'.$helpers.'::h0_0($kept)');
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($kept)->toBe([true, [(string) (new ReflectionClass($helpers))->getFileName()]])
        ->and($seconds)->toBeLessThan(5.0);
});
