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
 * A cache decorator around any {@see TypeEngine}. `analyzeAction` and `classMetadata` are served from the
 * {@see EngineResultCache} on a hit and stored on a miss; `trace()` never is, since it hands live
 * `PhpParser\Node`s to a stateful visitor and there's nothing serializable to replay.
 *
 * The hit path is byte-identical to the miss path: the cache stores the canonical `toArray()`, and the
 * decorator returns the same object it would have computed.
 *
 * @internal
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
        // Not cached here: the handler files land in the route's dependency set, so the pipeline's
        // fragment cache already invalidates the whole route when a handler changes.
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
        // Never cached — a live, stateful visitor holding live nodes has nothing to store or replay.
        return $this->inner->trace($action, $visitor);
    }
}
