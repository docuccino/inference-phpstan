<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapterFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Tests\Support\ScriptedRuntimeAdapter;

/*
 * An action the engine finds no body for answers with nothing and one warning saying why. Nothing in that
 * answer is analysed: where a closure is depends on the source alone, and a pass that harvests no body is
 * what a no-emit adapter is. So the real engine is built through its factory's adapter seam, over an
 * adapter that emits nothing. What a found body answers with is the fixture group's.
 */

/** The engine as the factory wires it, over a pass that harvests no body. */
function bodyNotFoundEngine(): TypeEngine
{
    $adapters = new class extends RuntimeAdapterFactory
    {
        public function create(RuntimeConfig $config): RuntimeAdapter
        {
            return new ScriptedRuntimeAdapter;
        }
    };
    $runtime = new RuntimeConfig(
        projectRoot: '/tmp/docuccino-body-not-found',
        tmpDir: '/tmp/docuccino-body-not-found/tmp',
        phpVersion: PHP_VERSION_ID,
        projectPaths: ['/tmp/docuccino-body-not-found/app'],
    );

    return (new PhpStanEngineFactory($adapters))->create($runtime, EngineConfig::forProject('/tmp/docuccino-body-not-found/app'));
}

/** A routes file of the test's own, named for it alone and removed when the process ends. */
function bodyNotFoundRoutes(string $source): string
{
    $file = sys_get_temp_dir().'/docuccino-body-not-found-'.getmypid().'-'.md5($source).'.php';
    file_put_contents($file, $source);
    register_shutdown_function(static fn () => @unlink($file));

    return $file;
}

it('looks a closure route up by its line, and says when the line is why it has no body', function (): void {
    // Reflection gives a closure its file and line and nothing else, so two declared on one line cannot
    // be told apart: answering either would publish one route's responses as the other's. The author
    // can act on that, so the warning names it.
    $file = bodyNotFoundRoutes(<<<'PHP'
        <?php
        Route::get('/a', fn () => 1)->fallback(fn () => 2);
        Route::get('/b', fn () => 3);
        PHP);
    $engine = bodyNotFoundEngine();

    $paired = $engine->analyzeAction(new ActionRef($file, null, '{closure}', 2));
    $alone = $engine->analyzeAction(new ActionRef($file, null, '{closure}', 3));

    expect($paired->returns)->toBe([])
        ->and($paired->throws)->toBe([])
        ->and($paired->dependencyFiles)->toBe([$file])
        ->and($paired->diagnostics)->toHaveCount(1)
        ->and($paired->diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($paired->diagnostics[0]->code)->toBe('inference.method-not-found')
        ->and($paired->diagnostics[0]->message)->toContain('line 2 declares more than one closure')
        // One closure on its line is found by that line, as a closure, never looked up as a method: here
        // the pass harvested no body for it, and the warning says which line it looked at.
        ->and($alone->diagnostics)->toHaveCount(1)
        ->and($alone->diagnostics[0]->message)->toContain('No analysable closure body')
        ->and($alone->diagnostics[0]->message)->toContain('at line 3');
});

it('still looks a controller action up as a method by its class and name', function (): void {
    $file = bodyNotFoundRoutes("<?php\nfinal class ShowController { public function show() { return 1; } }\n");

    $analysis = bodyNotFoundEngine()->analyzeAction(new ActionRef($file, 'ShowController', 'show', 2));

    expect($analysis->diagnostics)->toHaveCount(1)
        ->and($analysis->diagnostics[0]->code)->toBe('inference.method-not-found')
        ->and($analysis->diagnostics[0]->message)->toContain('No analysable method body for');
});
