<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Analysis\AccessorKind;
use Docuccino\Inference\PhpStan\Analysis\ConstructorEchoes;
use Docuccino\Inference\PhpStan\Analysis\ParamAccessor;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\EchoedProblem;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\EchoingBase;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\InheritsEchoes;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\InlineEchoedProblem;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\OwnTableEchoes;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\PromotedEchoes;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\SerialisedEchoes;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes\UnfixedEchoes;

/*
 * Which members of an object body its constructor reads off a parameter. The answer is what EVERY instance
 * sent holds, so it is given only where PHP fixes it — a public readonly property, its first write reached
 * by every completing path — on a class whose JSON is its public properties. Real reflection and real
 * source, over probes written the way such a class is.
 */
it('reads the members a constructor takes off its parameters, and only those PHP fixes', function (string $class, array $expected): void {
    expect((new ConstructorEchoes(static function (string $file): void {}))->of($class))->toEqual($expected);
})->with(function (): array {
    $status = new ParamAccessor('rendered', AccessorKind::Method, 'getStatusCode');

    return [
        // `$this->status` is read at the value its own first write gave it, since reading it any earlier throws.
        'the status, and its phrase through the property holding it' => [EchoedProblem::class, [
            'status' => ['accessor' => $status, 'text' => false, 'fallback' => null],
            'title' => ['accessor' => $status, 'text' => true, 'fallback' => new LiteralT('Error')],
        ]],
        'the phrase read inline, through a class inheriting the table, with no `??`' => [InlineEchoedProblem::class, [
            'detail' => ['accessor' => ParamAccessor::identity('detail'), 'text' => false, 'fallback' => null],
            'title' => ['accessor' => $status, 'text' => true, 'fallback' => null],
        ]],
        // A promoted property holds its argument, and a constant fallback is the value it names.
        'a promoted status, and its phrase through it falling back to a constant' => [PromotedEchoes::class, [
            'status' => ['accessor' => ParamAccessor::identity('status'), 'text' => false, 'fallback' => null],
            'title' => ['accessor' => ParamAccessor::identity('status'), 'text' => true, 'fallback' => new LiteralT('Unknown status')],
        ]],
        // `self::` is the class the line is written in, which inherits Symfony's table.
        'the constructor a base class wrote' => [EchoingBase::class, [
            'title' => ['accessor' => $status, 'text' => true, 'fallback' => new LiteralT('Error')],
        ]],
        'the constructor a subclass inherits' => [InheritsEchoes::class, [
            'title' => ['accessor' => $status, 'text' => true, 'fallback' => new LiteralT('Error')],
        ]],
        'writable, unreached, or keyed through a writable property' => [UnfixedEchoes::class, [
            'status' => ['accessor' => ParamAccessor::identity('status'), 'text' => false, 'fallback' => null],
        ]],
        'a body that serialises itself' => [SerialisedEchoes::class, []],
        'a table that only shares the name' => [OwnTableEchoes::class, []],
        'a class that is not there' => ['App\\Missing\\Problem', []],
    ];
});

it('records every file the answer was read out of, each time it is asked', function (): void {
    $touched = [];
    $echoes = new ConstructorEchoes(static function (string $file) use (&$touched): void {
        $touched[] = basename($file);
    });

    $echoes->of(InheritsEchoes::class);
    $echoes->of(InheritsEchoes::class);

    // The subclass declares what the hierarchy says of each property; the base writes the constructor; and
    // `self::$statusTexts` is Symfony's table because of what the base's hierarchy declares.
    $once = ['InheritsEchoes.php', 'EchoingBase.php', 'Response.php', 'EchoingBase.php', 'EchoingBase.php', 'Response.php'];
    expect($touched)->toBe([...$once, ...$once]);
});

it('records the file a constant fallback is copied out of', function (): void {
    $touched = [];
    (new ConstructorEchoes(static function (string $file) use (&$touched): void {
        $touched[] = basename($file);
    }))->of(PromotedEchoes::class);

    expect($touched)->toBe(['PromotedEchoes.php', 'PromotedEchoes.php', 'PromotedEchoes.php', 'Response.php']);
});
