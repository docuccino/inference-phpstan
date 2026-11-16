<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A real-time facade of the response factory hands every call to the factory, so
 * `Facades\Illuminate\Contracts\Routing\ResponseFactory::json($body, 422)` is sent exactly as
 * `response()->json($body, 422)` is ({@see ResponseFacadeStatusTest}). Laravel writes such a facade into the
 * application's `storage/` the first time it is named. Analysis names it where nothing can be written, so
 * the engine declares it in memory: every action in a file naming one is analysed, and `storage/` is left
 * as it was found.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, string> each file under the fixture app's `storage/`, as its size and modification time */
function realTimeFacadeStorage(): array
{
    $files = [];
    $root = FixtureRunner::path('storage');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $files[substr($file->getPathname(), strlen($root))] = $file->getSize().'@'.$file->getMTime();
        }
    }
    ksort($files);

    return $files;
}

/** @return array{analyses: array<string, array<string, mixed>>, before: array<string, string>, after: array<string, string>} */
function realTimeFacadeRun(): array
{
    static $run = null;

    if ($run === null) {
        $before = realTimeFacadeStorage();
        $analyses = FixtureRunner::analyzeMany(
            'app/Http/Controllers/RealTimeFacadeController.php',
            'App\\Http\\Controllers\\RealTimeFacadeController',
            ['json', 'noContent', 'make', 'plain'],
        );
        $run = ['analyses' => $analyses, 'before' => $before, 'after' => realTimeFacadeStorage()];
    }

    return $run;
}

function realTimeFacadeReturn(string $method): DType
{
    $analysis = ActionAnalysis::fromArray(realTimeFacadeRun()['analyses'][$method]);
    expect($analysis->returns)->toHaveCount(1)
        ->and($analysis->diagnostics)->toBe([]);

    return $analysis->returns[0]->type;
}

it('sends a call through a real-time facade with the body and status the factory sends it with', function (string $method, DType $payload, int $status): void {
    expect(realTimeFacadeReturn($method))->toEqual(new ClassT('Illuminate\\Http\\JsonResponse', [$payload, new LiteralT($status)]));
})->with([
    'ResponseFactory::json($body, 422), imported' => ['json', new ArrayShapeT([new ArrayShapeField('error', new LiteralT('locked'))]), 422],
    'ResponseFactory::noContent(205), imported' => ['noContent', new VoidT, 205],
    '\\Facades\\…\\ResponseFactory::make(\'\', 410), written out' => ['make', new VoidT, 410],
])->group('fixture');

it('analyses an action that names no facade, in a file that names one', function (): void {
    // The facade was the analysis of the whole file, so its failure was every action's.
    expect(realTimeFacadeReturn('plain'))->toEqual(new ClassT('Illuminate\\Http\\JsonResponse', [new ArrayShapeT([new ArrayShapeField('ok', new LiteralT(true))]), new LiteralT(200)]));
})->group('fixture');

it('leaves the application\'s storage as it found it', function (): void {
    $run = realTimeFacadeRun();

    expect($run['after'])->toBe($run['before'])
        ->and(array_filter(array_keys($run['after']), static fn (string $path): bool => str_contains($path, '/facade-')))->toBe([])
        // A listing that saw nothing would agree with itself.
        ->and($run['after'])->toHaveKey('/framework/cache/.gitignore');
})->group('fixture');
