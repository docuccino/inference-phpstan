<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Cache\EngineResultCache;
use Docuccino\Inference\PhpStan\Cache\NullEngineResultCache;
use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Orchestration\OrchestratedTypeEngine;
use Docuccino\Inference\PhpStan\Orchestration\OrchestrationConfig;
use Docuccino\Inference\PhpStan\Orchestration\WorkerPool;
use Docuccino\Inference\PhpStan\Runtime\BootFailedException;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapterFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Runtime\UnsupportedPhpStanVersionException;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;

/**
 * Builds a booted {@see PhpStanTypeEngine}, wiring the per-minor adapter,
 * translator, file analyzer, project filter and metadata factory. If the
 * container/Larastan bootstrap fails or the PHPStan version is unsupported, it
 * degrades to {@see NullTypeEngine} so docblock/attribute-only docs still build
 * (design §3) — the caller always gets a total {@see TypeEngine}.
 *
 * Three composition modes: {@see create()} (single-process, Phase 2a),
 * {@see createCaching()} (single-process behind the result cache), and
 * {@see createOrchestrated()} (the worker pool — Phase 2b).
 */
final class PhpStanEngineFactory
{
    public function __construct(
        private readonly RuntimeAdapterFactory $adapterFactory = new RuntimeAdapterFactory,
    ) {}

    public function create(RuntimeConfig $runtimeConfig, EngineConfig $engineConfig): TypeEngine
    {
        try {
            $adapter = $this->adapterFactory->create($runtimeConfig);
            $adapter->boot();
        } catch (BootFailedException|UnsupportedPhpStanVersionException) {
            return new NullTypeEngine;
        }

        $translator = new TypeTranslator;
        $fileAnalyzer = new FileAnalyzer($adapter);
        $normalize = static fn (string $path): string => $adapter->normalize($path);
        // DESCEND scope (throws / QB-trace / inline-rules): the bounded interprocedural set.
        $projectFilter = new ProjectFilter($engineConfig->projectPaths, $normalize);
        // PRIME scope (response-shape refiner + enum folder): every primed app source root, so an
        // error-render helper in a modular `Modules\…` root folds too. Vendor is not a primed root, so
        // the vendor containment is unchanged. Falls back to the descend scope when no prime scope was
        // configured (they coincide for a non-modular app).
        $refinerFilter = new ProjectFilter(
            $runtimeConfig->projectPaths !== [] ? $runtimeConfig->projectPaths : $engineConfig->projectPaths,
            $normalize,
        );

        return new PhpStanTypeEngine(
            adapter: $adapter,
            config: $engineConfig,
            translator: $translator,
            fileAnalyzer: $fileAnalyzer,
            projectFilter: $projectFilter,
            classMetadataFactory: new ClassMetadataFactory,
            refinerFilter: $refinerFilter,
        );
    }

    /**
     * Single-process engine behind the result cache (design §8). Falls back to a
     * cache-less {@see NullTypeEngine} on boot failure, exactly like {@see create()}.
     */
    public function createCaching(
        RuntimeConfig $runtimeConfig,
        EngineConfig $engineConfig,
        EngineResultCache $cache,
    ): TypeEngine {
        $inner = $this->create($runtimeConfig, $engineConfig);
        if ($inner instanceof NullTypeEngine) {
            return $inner;
        }

        return new CachingTypeEngine(
            $inner,
            $cache,
            VersionFingerprint::forRuntime($runtimeConfig),
        );
    }

    /**
     * The orchestrated engine (design §3): `analyzeAction` fans out across a
     * {@see WorkerPool}; `classMetadata`/`trace` stay in-process, lazily booted and
     * cache-wrapped. The parent never boots a container up front — workers boot
     * their own, and the in-process engine boots only if trace/metadata is used.
     */
    public function createOrchestrated(
        RuntimeConfig $runtimeConfig,
        EngineConfig $engineConfig,
        OrchestrationConfig $orchestrationConfig,
        EngineResultCache $cache = new NullEngineResultCache,
    ): TypeEngine {
        $fingerprint = VersionFingerprint::forRuntime($runtimeConfig);
        $pool = new WorkerPool($orchestrationConfig, $cache, $fingerprint);

        return new OrchestratedTypeEngine(
            $pool,
            fn (): TypeEngine => $this->inProcessFor($runtimeConfig, $engineConfig, $cache, $fingerprint),
        );
    }

    private function inProcessFor(
        RuntimeConfig $runtimeConfig,
        EngineConfig $engineConfig,
        EngineResultCache $cache,
        VersionFingerprint $fingerprint,
    ): TypeEngine {
        $inner = $this->create($runtimeConfig, $engineConfig);
        if ($inner instanceof NullTypeEngine || $cache instanceof NullEngineResultCache) {
            return $inner;
        }

        return new CachingTypeEngine($inner, $cache, $fingerprint);
    }
}
