<?php

declare(strict_types=1);

/*
 * Docuccino inference worker entrypoint (design §3).
 *
 * Spawned by the parent WorkerPool as:
 *   php bin/worker.php <bootstrap> <maxActions> <rssLimitBytes>
 *
 * The <bootstrap> is a host-supplied PHP file that (a) sets up autoloading for
 * both the host Laravel app and the docuccino packages and (b) returns a
 * constructed Docuccino\Core\Inference\TypeEngine. We require it FIRST so the
 * WorkerLoop / TypeEngine classes referenced below become autoloadable, then run
 * the request/response loop over stdin/stdout.
 */

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Orchestration\WorkerLoop;

$bootstrap = $argv[1] ?? '';
$maxActions = (int) ($argv[2] ?? 50);
$rssLimit = (int) ($argv[3] ?? 1_073_741_824);

if ($bootstrap === '' || ! is_file($bootstrap)) {
    fwrite(STDERR, "docuccino worker: bootstrap file not found: {$bootstrap}\n");
    exit(64);
}

/** @var mixed $engine */
$engine = require $bootstrap;

if (! $engine instanceof TypeEngine) {
    fwrite(STDERR, "docuccino worker: bootstrap did not return a TypeEngine\n");
    exit(65);
}

(new WorkerLoop($engine, $maxActions, $rssLimit, STDIN, STDOUT))->run();
