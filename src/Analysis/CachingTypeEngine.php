<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Cache\EngineResultCache;
use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;

/**
 * A cache decorator around any {@see TypeEngine} (design §8). `analyzeAction` and
 * `classMetadata` are cacheable — served from the {@see EngineResultCache} on a
 * hit, recomputed then stored on a miss. `trace()` is never cached: it hands
 * live `PhpParser\Node`s to a stateful visitor, which cannot round-trip through a
 * serialized store — it always delegates to the inner engine.
 *
 * The hit path returns a value byte-identical to the miss path because the cache
 * stores the canonical `toArray()` and the decorator returns the same object it
 * would have computed.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final readonly class CachingTypeEngine implements TypeEngine
{
    public function __construct(
        private TypeEngine $inner,
        private EngineResultCache $cache,
        private VersionFingerprint $fingerprint,
    ) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        $cached = $this->cache->getAction($action, $this->fingerprint);
        if ($cached !== null) {
            return $cached;
        }

        $analysis = $this->inner->analyzeAction($action);
        $this->cache->putAction($action, $analysis, $this->fingerprint);

        return $analysis;
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        // Not cached at the engine level: the inferred-handler integration records the handler
        // files it read into the route's dependency set, so the pipeline's OperationFragment cache
        // (design §10) already invalidates the whole route when a handler changes.
        return $this->inner->analyzeCallable($callable);
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        $cached = $this->cache->getClass($class, $this->fingerprint);
        if ($cached !== null) {
            return $cached;
        }

        $metadata = $this->inner->classMetadata($class);
        $this->cache->putClass($class, $metadata, $this->fingerprint);

        return $metadata;
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        // Never cached: the visitor is a live, stateful object handed live
        // `PhpParser\Node`s, so there is nothing serializable to store or replay.
        // The inner engine's report (dependency files) passes straight through.
        return $this->inner->trace($action, $visitor);
    }
}
