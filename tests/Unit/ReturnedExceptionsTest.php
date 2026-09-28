<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Inference\PhpStan\Throwing\ReturnedExceptions;

/**
 * The rule an exception mapper's analysis answers by, without a scope: the read of each return is scripted,
 * so what is left is what the answer does with it. The real read is the fixture group's `ExceptionMapTest`.
 */
function returnedThrow(string $fqcn, ?int $status): ThrownException
{
    return new ThrownException($fqcn, $status, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
}

it('answers with every exception the returns build, and asks nothing of a return handing the parameter back', function (): void {
    $returns = [
        new ReturnSite(new ClassT('App\\Payment'), new SourceLocation('m.php', 3)),
        new ReturnSite(new ClassT('App\\Missing'), new SourceLocation('m.php', 5), returnsParameter: 'e'),
        new ReturnSite(new ClassT('App\\Conflict'), new SourceLocation('m.php', 7)),
    ];
    $asked = [];

    $answer = ReturnedExceptions::of($returns, static function (int $index) use (&$asked): array {
        $asked[] = $index;

        return [$index === 0 ? returnedThrow('App\\Payment', 402) : returnedThrow('App\\Conflict', 409)];
    });

    // A return handing the parameter back translates nothing, so it is neither read nor rewritten.
    expect($asked)->toBe([0, 2])
        ->and($answer['returns'])->toBe($returns)
        ->and(array_map(static fn (ThrownException $t): array => [$t->exceptionFqcn, $t->httpStatusHint], $answer['throws']))
        ->toBe([['App\\Payment', 402], ['App\\Conflict', 409]]);
});

it('publishes a return that builds nothing it can name as unknown, not as the type it was declared', function (): void {
    $returns = [new ReturnSite(new ClassT('Throwable'), new SourceLocation('m.php', 3), returnsParameter: null)];

    $answer = ReturnedExceptions::of($returns, static fn (int $index): array => []);

    expect($answer['throws'])->toBe([])
        ->and($answer['returns'][0]->type)->toBeInstanceOf(UnknownT::class)
        ->and($answer['returns'][0]->location)->toBe($returns[0]->location);
});

it('answers nothing for a callable with no returns', function (): void {
    expect(ReturnedExceptions::of([], static fn (int $index): array => [returnedThrow('App\\X', 1)]))
        ->toBe(['returns' => [], 'throws' => []]);
});
