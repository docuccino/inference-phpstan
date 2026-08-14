<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

/**
 * Exists only so {@see NativeTypeProbe} can declare a `parent` return type — the third label of the
 * mapper's `self`/`static`/`parent` arm. Only ever reflected.
 */
class NativeTypeProbeBase {}
