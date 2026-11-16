<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http\ProbeStatusValue;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;
use Docuccino\Inference\PhpStan\Throwing\CaseStatus;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Reflection\ReflectionProviderStaticAccessor;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Enum\EnumCaseObjectType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * A `->value` read on an enum case that PHPStan already folded to its constant, and on a choice of cases.
 * Folding a method over a receiver's cases needs PHPStan's analyser, so it is proven where that runs — the
 * `fixture` group (`CaseStatusResponseTest`, `ThrowStatusTest`).
 *
 * @param  Closure(Node\Expr): Type  $types
 * @return array{array{status: int|null, files: list<string>}, string}
 */
function caseStatusOf(string $code, Closure $types): array
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';') ?? [];
    $read = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Expr\PropertyFetch::class);
    expect($read)->toBeInstanceOf(Node\Expr\PropertyFetch::class);

    $scope = test()->createStub(Scope::class);
    $scope->method('getType')->willReturnCallback($types);

    $adapter = new ScriptedRuntimeAdapter([]);
    $files = new FileAnalyzer($adapter, new FileWalks($adapter));
    $status = new CaseStatus($files, new ProjectFilter(['/'], static fn (string $file): string => $file));

    return [$status->of($read, $scope), (string) (new ReflectionEnum(ProbeStatusValue::class))->getFileName()];
}

it('depends on the enum a backing value PHPStan already folded is decided in', function (): void {
    // The number is the case's backing value: editing `case Gone = 410` changes the answer, so the enum's
    // file has to invalidate it even though the scope handed the constant over without a fold.
    [$folded, $enum] = caseStatusOf('Fault::Gone->value', static fn (Node\Expr $expr): Type => $expr instanceof Node\Expr\PropertyFetch
        ? new ConstantIntegerType(410)
        : new EnumCaseObjectType(ProbeStatusValue::class, 'Gone'));

    expect($folded)->toBe(['status' => 410, 'files' => [$enum]]);
});

/**
 * Whether the status expression `$code` reads cases that disagree, with `$receiver` the type of what it is
 * read off.
 */
function caseStatusDisagrees(string $code, Type $receiver): bool
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';')[0] ?? null;
    expect($parsed)->toBeInstanceOf(Node\Stmt\Expression::class);

    $scope = test()->createStub(Scope::class);
    $scope->method('getType')->willReturn($receiver);

    $adapter = new ScriptedRuntimeAdapter([]);
    $files = new FileAnalyzer($adapter, new FileWalks($adapter));
    $status = new CaseStatus($files, new ProjectFilter(['/'], static fn (string $file): string => $file));

    // Comparing object types asks PHPStan's static reflection, which only a booted analyser registers.
    $accessor = new ReflectionProperty(ReflectionProviderStaticAccessor::class, 'instance');
    $previous = $accessor->getValue();
    ReflectionProviderStaticAccessor::registerInstance(test()->createStub(ReflectionProvider::class));
    try {
        return $status->disagrees($parsed->expr, $scope);
    } finally {
        $accessor->setValue(null, $previous);
    }
}

it('reads a backing value off a choice of cases as no status where the cases back different ones', function (Type $receiver, bool $disagrees): void {
    // `$fault->value` on Gone|Locked is typed 410|423, but the request gets one case: publishing both codes
    // would promise each request a status only the other case sends.
    expect(caseStatusDisagrees('$fault->value', $receiver))->toBe($disagrees);
})->with([
    'Gone or Locked, 410 or 423' => [new UnionType([new EnumCaseObjectType(ProbeStatusValue::class, 'Gone'), new EnumCaseObjectType(ProbeStatusValue::class, 'Locked')]), true],
    // One case is one status, which the read states outright.
    'Gone alone' => [new EnumCaseObjectType(ProbeStatusValue::class, 'Gone'), false],
    // A null beside the cases is a value no case names, so the cases are not the whole answer.
    'Gone, Locked or null' => [new UnionType([new EnumCaseObjectType(ProbeStatusValue::class, 'Gone'), new EnumCaseObjectType(ProbeStatusValue::class, 'Locked'), new NullType]), false],
]);

it('reads no disagreement into a status that is no read on a case', function (): void {
    expect(caseStatusDisagrees('$status', new UnionType([new ConstantIntegerType(410), new ConstantIntegerType(423)])))->toBeFalse();
});
