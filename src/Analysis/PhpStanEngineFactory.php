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
 * Builds a booted {@see PhpStanTypeEngine}, wiring the per-minor adapter, translator, file analyzer, project
 * filter and metadata factory. A failed container/Larastan bootstrap or an unsupported PHPStan version
 * degrades to {@see NullTypeEngine}, so docblock/attribute-only docs still build and the caller always gets
 * a total {@see TypeEngine}.
 *
 * Three modes: {@see create()} single-process, {@see createCaching()} behind the result cache, and
 * {@see createOrchestrated()} across the worker pool.
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
        // Descend scope (throws / QB-trace / inline-rules): the bounded interprocedural set.
        $projectFilter = new ProjectFilter($engineConfig->projectPaths, $normalize);
        // Prime scope (refiner + enum folder): every primed app source root, so a render helper in a
        // modular `Modules\…` root folds too. Vendor isn't a primed root, so containment is unchanged.
        // Falls back to the descend scope, which is the same thing for a non-modular app.
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

    /** Single-process engine behind the result cache; falls back like {@see create()} on boot failure. */
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
     * `analyzeAction` fans out across a {@see WorkerPool}; `classMetadata`/`trace` stay in-process, lazily
     * booted and cache-wrapped. The parent boots no container up front — workers boot their own, and the
     * in-process engine boots only if trace/metadata is actually used.
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
