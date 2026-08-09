<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;

/**
 * The engine's cache-busting version, first component of every {@see VersionFingerprint}. Bump it whenever
 * how analysis is produced or serialized changes, so stale entries are ignored.
 *
 * @internal
 */
final class EngineVersion
{
    public const string ID = '0.2.0-phase2b';
}
