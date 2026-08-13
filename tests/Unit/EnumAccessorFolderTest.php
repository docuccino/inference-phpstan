<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Analysis\AccessorKind;
use Docuccino\Inference\PhpStan\Analysis\EnumAccessorFolder;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Analysis\ParamAccessor;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\FolderProbeEnum;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\PlainProbeEnum;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

/**
 * In-process coverage for the reflection-only folds (`->value`/`->name`) and the project-only
 * containment gate for method folding. The `match ($this)` / plain-return BODY analysis needs a booted
 * PHPStan engine and is proven end-to-end by the --group=fixture ResponseShapeRefinementTest.
 *
 * @param  list<string>  $recorded  files the fold sink recorded (a spy for cache-soundness assertions)
 */
function makeEnumAccessorFolder(array &$recorded, bool $projectSees = false): EnumAccessorFolder
{
    $adapter = new class implements RuntimeAdapter
    {
        public function boot(): void {}

        public function prime(array $files): void {}

        public function processFile(string $file, callable $callback): void {}

        public function normalize(string $file): string
        {
            return $file;
        }

        public function stableScope(Scope $scope): Scope
        {
            return $scope;
        }

        public function reflectionProvider(): ReflectionProvider
        {
            throw new RuntimeException('not used in this unit');
        }
    };

    // A project path of `/` sees every file; an empty set sees none — how the method gate is exercised.
    $projectFilter = new ProjectFilter($projectSees ? ['/'] : [], static fn (string $f): string => $f);

    return new EnumAccessorFolder(
        new FileAnalyzer($adapter),
        $projectFilter,
        static function (string $file) use (&$recorded): void {
            $recorded[] = $file;
        },
    );
}

it('folds ->value to the case backing value and ->name to the case name (vendor-safe reflection)', function (): void {
    $recorded = [];
    $folder = makeEnumAccessorFolder($recorded);

    expect($folder->fold(FolderProbeEnum::class, 'Alpha', new ParamAccessor('p', AccessorKind::Value)))
        ->toEqual(new LiteralT('https://errors.test/alpha'))
        ->and($folder->fold(FolderProbeEnum::class, 'Beta', new ParamAccessor('p', AccessorKind::Name)))
        ->toEqual(new LiteralT('Beta'))
        // Reflection reads need no body analysis, so nothing is added to the dependency set.
        ->and($recorded)->toBe([]);
});

it('does not fold ->value on a non-backed enum or an unknown class', function (): void {
    $recorded = [];
    $folder = makeEnumAccessorFolder($recorded);

    expect($folder->fold(PlainProbeEnum::class, 'One', new ParamAccessor('p', AccessorKind::Value)))->toBeNull()
        ->and($folder->fold('App\\Nope\\Missing', 'X', new ParamAccessor('p', AccessorKind::Value)))->toBeNull();
});

it('does not fold an identity accessor (an enum object is not a documentable scalar)', function (): void {
    $recorded = [];
    $folder = makeEnumAccessorFolder($recorded);

    expect($folder->fold(FolderProbeEnum::class, 'Alpha', ParamAccessor::identity('p')))->toBeNull();
});

it('declines a method fold when the enum is not project code, and never records the file', function (): void {
    // The enum resolves, but the project filter sees no paths — the folder must not analyse the body.
    $recorded = [];
    $folder = makeEnumAccessorFolder($recorded, projectSees: false);
    $accessor = new ParamAccessor('p', AccessorKind::Method, 'status');

    expect($folder->fold(FolderProbeEnum::class, 'Alpha', $accessor))->toBeNull();

    // A second call hits the memo (still no file recorded — the gate rejected before any body analysis).
    expect($folder->fold(FolderProbeEnum::class, 'Alpha', $accessor))->toBeNull()
        ->and($recorded)->toBe([]);
});

it('returns null for a method accessor whose method or enum does not exist', function (): void {
    $recorded = [];
    $folder = makeEnumAccessorFolder($recorded, projectSees: true);

    expect($folder->fold(FolderProbeEnum::class, 'Alpha', new ParamAccessor('p', AccessorKind::Method, 'noSuchMethod')))->toBeNull()
        ->and($folder->fold('App\\Nope\\Missing', 'X', new ParamAccessor('p', AccessorKind::Method, 'status')))->toBeNull()
        ->and($recorded)->toBe([]);
});

it('records the enum file and analyses it when the method resolves to project code', function (): void {
    // projectSees: the resolved method file passes the gate, so the folder records it (cache soundness)
    // and drives analysis. Here the adapter yields no method bodies, so the fold is null — but the file
    // still joins the dependency set, exactly as a real fold would.
    $recorded = [];
    $folder = makeEnumAccessorFolder($recorded, projectSees: true);

    expect($folder->fold(FolderProbeEnum::class, 'Alpha', new ParamAccessor('p', AccessorKind::Method, 'status')))->toBeNull()
        ->and($recorded)->toHaveCount(1)
        ->and($recorded[0])->toEndWith('FolderProbeEnum.php');

    // Memo hit re-records the file (soundness survives memoisation).
    expect($folder->fold(FolderProbeEnum::class, 'Alpha', new ParamAccessor('p', AccessorKind::Method, 'status')))->toBeNull()
        ->and($recorded)->toHaveCount(2);
});
