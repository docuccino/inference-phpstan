<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Cache;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;

/**
 * The engine's own result cache (design §8). PHPStan's built-in result cache is
 * CLI-rule-oriented and unusable for us; this caches the two serializable engine
 * outputs — per-{@see ActionRef} {@see ActionAnalysis} and per-{@see ClassRef}
 * {@see ClassMetadata} — keyed on the {@see VersionFingerprint} plus per-entry
 * file hashes, with depfile-style invalidation.
 *
 * Contract: a cache *hit* MUST return a value byte-identical (after canonical
 * serialization) to what a *miss* would have recomputed. Implementations tolerate
 * concurrent writers.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
interface EngineResultCache
{
    public function getAction(ActionRef $action, VersionFingerprint $fingerprint): ?ActionAnalysis;

    public function putAction(ActionRef $action, ActionAnalysis $analysis, VersionFingerprint $fingerprint): void;

    public function getClass(ClassRef $class, VersionFingerprint $fingerprint): ?ClassMetadata;

    public function putClass(ClassRef $class, ClassMetadata $metadata, VersionFingerprint $fingerprint): void;
}
