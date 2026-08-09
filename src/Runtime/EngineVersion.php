<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime;

use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;

/**
 * The engine's own cache-busting version. Bump on any change to how analysis is
 * produced or serialized so stale cache entries are ignored (it is the first
 * component of every {@see VersionFingerprint}).
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class EngineVersion
{
    public const string ID = '0.2.0-phase2b';
}
