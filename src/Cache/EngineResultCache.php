<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Cache;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;

/**
 * The engine's own result cache — PHPStan's is CLI-rule-oriented and unusable for us. Caches the two
 * serializable outputs, {@see ActionAnalysis} per {@see ActionRef} and {@see ClassMetadata} per
 * {@see ClassRef}, keyed on the {@see VersionFingerprint} plus per-entry file hashes with depfile-style
 * invalidation.
 *
 * The contract: a hit must return a value byte-identical, after canonical serialization, to what a miss
 * would have recomputed. Implementations tolerate concurrent writers.
 *
 * @internal
 */
interface EngineResultCache
{
    public function getAction(ActionRef $action, VersionFingerprint $fingerprint): ?ActionAnalysis;

    public function putAction(ActionRef $action, ActionAnalysis $analysis, VersionFingerprint $fingerprint): void;

    public function getClass(ClassRef $class, VersionFingerprint $fingerprint): ?ClassMetadata;

    public function putClass(ClassRef $class, ClassMetadata $metadata, VersionFingerprint $fingerprint): void;
}
