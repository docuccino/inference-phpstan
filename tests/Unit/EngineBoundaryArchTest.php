<?php

declare(strict_types=1);

/**
 * The engine sits BESIDE the Laravel adapter, not under or over it: it implements core's TypeEngine and
 * TypeEngineBuilder contracts and knows nothing about the host framework. Larastan analyses a booted
 * Laravel app, so the package does touch illuminate classes — always by FQCN string, never an import, so
 * a second adapter can host the same engine (and PHPStan's own analysis of this package stays free of
 * the host app's classes).
 */
arch('the engine never imports the Laravel adapter')
    ->expect('Docuccino\Inference\PhpStan')
    ->not->toUse('Docuccino\Laravel');

arch('the engine never imports the Laravel framework')
    ->expect('Docuccino\Inference\PhpStan')
    ->not->toUse('Illuminate');
