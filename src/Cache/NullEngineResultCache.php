<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Cache;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;

/**
 * The cache off-switch: every lookup misses, every store is a no-op, so callers can wire the cache in
 * unconditionally.
 *
 * @internal
 */
final readonly class NullEngineResultCache implements EngineResultCache
{
    public function getAction(ActionRef $action, VersionFingerprint $fingerprint): ?ActionAnalysis
    {
        return null;
    }

    public function putAction(ActionRef $action, ActionAnalysis $analysis, VersionFingerprint $fingerprint): void
    {
        // Intentionally empty.
    }

    public function getClass(ClassRef $class, VersionFingerprint $fingerprint): ?ClassMetadata
    {
        return null;
    }

    public function putClass(ClassRef $class, ClassMetadata $metadata, VersionFingerprint $fingerprint): void
    {
        // Intentionally empty.
    }
}
