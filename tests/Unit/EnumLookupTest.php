<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\EnumLookup;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;

/**
 * What `BackedEnum::from()` throws for each value it can be handed, stated from PHP's own rules rather than
 * read back off the class: a value of the backing type returns the case it backs or throws `ValueError`; any
 * other is a `TypeError` where the calling file declares `strict_types`, and coerced where it does not.
 */
it('answers what from() throws for every value it may be handed', function (array $values, array $backing, bool $intBacked, bool $strict, ?array $expected): void {
    expect(EnumLookup::throws($values, $backing, $intBacked, $strict)[0] ?? null)->toBe($expected);
})->with([
    // A value a case is backed by returns that case, so neither error can happen.
    'an int a case backs' => [[423], [410, 423], true, true, []],
    'a string a case backs' => [['draft'], ['draft', 'published'], false, false, []],
    // A value of the backing type no case backs always throws ValueError, and never TypeError.
    'an int no case backs' => [[999], [410, 423], true, true, ['ValueError' => true]],
    'a string no case backs' => [['gone'], ['draft'], false, true, ['ValueError' => true]],
    // An enum with no cases backs nothing.
    'any int, on an enum with no cases' => [[1], [], true, false, ['ValueError' => true]],
    // Matching is strict, as PHP's is for a value of the backing type: '423' is not the int 423.
    'a numeric string for a string-backed enum' => [['423'], ['0423'], false, true, ['ValueError' => true]],
    // Under strict_types PHP refuses any other type before a case is looked up.
    'a string for an int-backed enum, strict' => [['423'], [423], true, true, ['TypeError' => true]],
    'an int for a string-backed enum, strict' => [[423], ['423'], false, true, ['TypeError' => true]],
    'null, strict' => [[null], [0], true, true, ['TypeError' => true]],
    'a float, strict' => [[423.0], [423], true, true, ['TypeError' => true]],
    // Without it PHP coerces, by rules this read does not repeat, so the declaration stands.
    'a string for an int-backed enum, coercive' => [['423'], [423], true, false, null],
    'a float, coercive' => [[423.0], [423], true, false, null],
    // Several values: a class is certain only where every value throws it.
    'two values, both backed' => [[410, 423], [410, 423], true, true, []],
    'two values, one backed' => [[410, 999], [410, 423], true, true, ['ValueError' => false]],
    'two values, neither backed' => [[998, 999], [410, 423], true, true, ['ValueError' => true]],
    'an unbacked int beside a string, strict' => [[999, '423'], [423], true, true, ['TypeError' => false, 'ValueError' => false]],
    'a backed int beside a string, coercive' => [[423, '423'], [423], true, false, null],
    // No value says nothing about what one would do.
    'no value' => [[], [423], true, true, null],
]);

it('answers whether from() throws for every value it may be handed', function (array $values, array $backing, bool $strict, bool $expected): void {
    // Every value throwing, whichever error each throws, is what makes the call it is handed to unreachable.
    expect(EnumLookup::throws($values, $backing, true, $strict)[1] ?? false)->toBe($expected);
})->with([
    'an int no case backs' => [[999], [410, 423], true, true],
    'an int a case backs' => [[423], [410, 423], true, false],
    'two values, one backed' => [[410, 999], [410, 423], true, false],
    // Two values throwing two different errors still both throw.
    'an unbacked int beside a string, strict' => [[999, '423'], [423], true, true],
    // Coercion decides, so nothing is known.
    'a string, coercive' => [['999'], [423], false, false],
    'no value' => [[], [423], true, false],
]);

/** The one expression a line of code is. */
function lookupCall(string $code): Node\Expr
{
    $stmts = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';');
    $stmt = $stmts[0] ?? null;

    return $stmt instanceof Node\Stmt\Expression ? $stmt->expr : throw new RuntimeException('not an expression: '.$code);
}

it('leaves the declaration standing where the call is no from() handed a constant', function (string $code, string $method): void {
    $scope = $this->createStub(Scope::class);
    // No argument is constant here, so every row is refused before the enum is reflected.
    $scope->method('getType')->willReturn(new IntegerType);

    $reached = $this->createStub(ExtendedMethodReflection::class);
    $reached->method('getName')->willReturn($method);

    expect(EnumLookup::of(lookupCall($code), $scope, $reached))->toBeNull();
})->with([
    'another method' => ['ExportFault::tryFrom(423)', 'tryFrom'],
    'no argument' => ['ExportFault::from()', 'from'],
    'a spread argument' => ['ExportFault::from(...$values)', 'from'],
    'a first-class callable' => ['ExportFault::from(...)', 'from'],
    'an argument named for another parameter' => ['ExportFault::from(other: 423)', 'from'],
    'an argument no constant pins' => ['ExportFault::from($code)', 'from'],
    'the value, named, no constant pinning it' => ['ExportFault::from(value: $code)', 'from'],
    'not a call' => ['$fault', 'from'],
]);

it('leaves a function call alone', function (): void {
    $scope = $this->createStub(Scope::class);
    $scope->method('getType')->willReturnCallback(static fn (): Type => new IntegerType);

    expect(EnumLookup::of(lookupCall('from(423)'), $scope, $this->createStub(FunctionReflection::class)))->toBeNull()
        ->and(EnumLookup::of(lookupCall('from(423)'), $scope, null))->toBeNull();
});
