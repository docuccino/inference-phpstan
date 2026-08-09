<?php

declare(strict_types=1);

/*
 * Fast (PHPStan-free) worker bootstrap for the non-fixture orchestration tests.
 * Loads the monorepo's root autoloader (which maps the docuccino packages) and
 * returns a StubTypeEngine — optionally poison-wrapped via DOCUCCINO_POISON_SYMBOL
 * to drive the pool's crash-containment path without booting Laravel/PHPStan.
 */

use Docuccino\Inference\PhpStan\Tests\Support\PoisonInjectingTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\StubTypeEngine;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$engine = new StubTypeEngine;

$poison = getenv('DOCUCCINO_POISON_SYMBOL');
if (is_string($poison) && $poison !== '') {
    return new PoisonInjectingTypeEngine($engine);
}

return $engine;
