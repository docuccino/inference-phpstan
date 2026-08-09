<?php

declare(strict_types=1);

/*
 * Worker bootstrap for the fixture-app orchestration tests.
 *
 * `bin/worker.php` requires this file at worker startup; it must set up
 * autoloading for the fixture Laravel app AND the docuccino packages under test,
 * then RETURN a constructed TypeEngine. It mirrors the loading strategy of
 * tests/bin/engine-runner.php (fixture-app vendor only + a hand-registered PSR-4
 * map) so the sole phpstan/php-parser in play is the fixture app's.
 *
 * Env:
 *   DOCUCCINO_POISON_SYMBOL  optional "Class::method"; that action crashes the
 *                            worker (drives bisection/containment tests).
 */

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Tests\Support\PoisonInjectingTypeEngine;

// A worker cold-compiles a PHPStan container and analyses the fixture app; like engine-runner.php it
// needs a generous memory ceiling (the default CLI limit OOMs a refinement-heavy action, which the
// crash-containment would then degrade to a poisoned unknown — a cold-vs-warm divergence, not a real
// result). Mirrors engine-runner's 2G.
ini_set('memory_limit', '2G');

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

$tmp = sys_get_temp_dir().'/docuccino-worker-'.getmypid().'-'.bin2hex(random_bytes(4));
@mkdir($tmp, 0o777, true);

$engine = (new PhpStanEngineFactory)->create(
    new RuntimeConfig($app, $tmp, PHP_VERSION_ID, [$app.'/app']),
    EngineConfig::forProject($app.'/app'),
);

$poison = getenv('DOCUCCINO_POISON_SYMBOL');
if (is_string($poison) && $poison !== '') {
    $engine = new PoisonInjectingTypeEngine($engine);
}

// The value the require in bin/worker.php receives.
return $engine instanceof TypeEngine ? $engine : null;
