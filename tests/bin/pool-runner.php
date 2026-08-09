<?php

declare(strict_types=1);

/*
 * Fixture-app orchestration runner (integration-test subprocess).
 *
 * Drives the full orchestrated engine (parent WorkerPool + K worker subprocesses)
 * over a fixed set of fixture controller actions and prints the sorted, serialized
 * results. Run out-of-process for the same reason as engine-runner.php: to keep
 * the fixture app's Laravel/Larastan out of the Pest process.
 *
 * Usage:
 *   php pool-runner.php <workers> <maxActionsPerWorker> [cacheDir]
 *
 * Env:
 *   DOCUCCINO_POISON_SYMBOL  optional "Class::method" that crashes its worker.
 *
 * Emits `@@RESULT@@` followed by one JSON line: { "<id>": <ActionAnalysis>, … }.
 */

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Cache\FilesystemEngineResultCache;
use Docuccino\Inference\PhpStan\Cache\NullEngineResultCache;
use Docuccino\Inference\PhpStan\Orchestration\OrchestratedTypeEngine;
use Docuccino\Inference\PhpStan\Orchestration\OrchestrationConfig;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;

$repoRoot = dirname(__DIR__, 4);
$app = $repoRoot.'/tests/fixture-app/app';

require $app.'/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $map = [
        'Docuccino\\Core\\' => $repoRoot.'/packages/core/src/',
        'Docuccino\\Inference\\PhpStan\\Tests\\' => $repoRoot.'/packages/inference-phpstan/tests/',
        'Docuccino\\Inference\\PhpStan\\' => $repoRoot.'/packages/inference-phpstan/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

$workers = max(1, (int) ($argv[1] ?? 1));
$maxActions = max(1, (int) ($argv[2] ?? 50));
$cacheDir = $argv[3] ?? '';

$ctrl = static fn (string $file): string => $app.'/app/Http/Controllers/'.$file;

/** @var list<ActionRef> $refs */
$refs = [
    new ActionRef($ctrl('SpikeController.php'), 'App\\Http\\Controllers\\SpikeController', 'listUsers'),
    new ActionRef($ctrl('SpikeController.php'), 'App\\Http\\Controllers\\SpikeController', 'jsonShape'),
    new ActionRef($ctrl('SpikeController.php'), 'App\\Http\\Controllers\\SpikeController', 'resourceCollection'),
    new ActionRef($ctrl('SpikeController.php'), 'App\\Http\\Controllers\\SpikeController', 'unionAction'),
    new ActionRef($ctrl('ThrowsController.php'), 'App\\Http\\Controllers\\ThrowsController', 'abortAction'),
    new ActionRef($ctrl('ThrowsController.php'), 'App\\Http\\Controllers\\ThrowsController', 'authorizeAction'),
    new ActionRef($ctrl('ThrowsController.php'), 'App\\Http\\Controllers\\ThrowsController', 'findOrFailAction'),
    new ActionRef($ctrl('ThrowsController.php'), 'App\\Http\\Controllers\\ThrowsController', 'deepUndeclared'),
    new ActionRef($ctrl('ThrowsController.php'), 'App\\Http\\Controllers\\ThrowsController', 'tryCatch'),
    new ActionRef($ctrl('UserListController.php'), 'App\\Http\\Controllers\\UserListController', 'listUsers'),
    // Response-shape refinement + enum-case accessor folding + StatusMarkerT + example-source: exercised
    // through the pool so this new engine output rides the 1-vs-N-worker and cold-vs-warm byte-identity
    // invariants, not just the single-run refinement fixture tests.
    new ActionRef($ctrl('ProblemController.php'), 'App\\Http\\Controllers\\ProblemController', 'forbidden'),
    // …and its unfolded-status sibling, so BOTH refined shapes ride the invariants: a folded literal
    // status (above) and a permissive status whose body member survives as a StatusMarkerT (here).
    new ActionRef($ctrl('ProblemController.php'), 'App\\Http\\Controllers\\ProblemController', 'dynamic'),
];

$cache = $cacheDir !== ''
    ? new FilesystemEngineResultCache($cacheDir)
    : new NullEngineResultCache;

$env = [];
$poison = getenv('DOCUCCINO_POISON_SYMBOL');
if (is_string($poison) && $poison !== '') {
    $env['DOCUCCINO_POISON_SYMBOL'] = $poison;
}

$orchestration = new OrchestrationConfig(
    workerBootstrap: __DIR__.'/worker-bootstrap.php',
    workers: $workers,
    maxActionsPerWorker: $maxActions,
    perActionTimeoutSeconds: 60.0,
    batchSize: 8,
    env: $env,
);

$engine = (new PhpStanEngineFactory)->createOrchestrated(
    new RuntimeConfig($app, sys_get_temp_dir().'/docuccino-pool-'.getmypid(), PHP_VERSION_ID, [$app.'/app']),
    EngineConfig::forProject($app.'/app'),
    $orchestration,
    $cache,
);

assert($engine instanceof OrchestratedTypeEngine);

$results = $engine->analyzeActions($refs);

$out = [];
foreach ($results as $id => $analysis) {
    $out[$id] = $analysis->toArray();
}

fwrite(STDOUT, "\n@@RESULT@@".json_encode($out, JSON_THROW_ON_ERROR)."\n");
