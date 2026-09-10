<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Runtime\BootFailedException;
use Docuccino\Inference\PhpStan\Runtime\FileWalks;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapterFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Runtime\UnsupportedPhpStanVersionException;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;

/**
 * Builds a booted {@see PhpStanTypeEngine}, wiring the per-minor adapter, translator, file analyzer, project
 * filter and metadata factory. A failed container/Larastan bootstrap or an unsupported PHPStan version
 * degrades to a {@see NullTypeEngine} carrying that error, so docblock/attribute-only docs still build, the
 * caller always gets a total {@see TypeEngine}, and the host can say what went wrong.
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
        } catch (BootFailedException|UnsupportedPhpStanVersionException $exception) {
            return new NullTypeEngine($exception->getMessage());
        }

        $translator = new TypeTranslator;
        // One recorder for the whole build: the method harvest and every trace of a file share its walk.
        $walks = new FileWalks($adapter);
        $fileAnalyzer = new FileAnalyzer($adapter, $walks);
        $normalize = static fn (string $path): string => $adapter->normalize($path);
        // Descend scope (throws / QB-trace / inline-rules): the bounded interprocedural set, which
        // `project_paths` is the knob for. It answers "how far may this build walk", and nothing else.
        $projectFilter = new ProjectFilter($engineConfig->projectPaths, $normalize);
        // Application scope: every primed app source root, so a class in a modular `Modules\…` root is
        // still the application's — which is the question a READ of a declaration asks (the refiner and
        // enum folder, and the status an exception class pins on itself), and the question actionability
        // asks of a notice. Vendor isn't a primed root, so containment is unchanged. Falls back to the
        // descend scope, which is the same thing for a non-modular app.
        $appFilter = new ProjectFilter(
            $runtimeConfig->projectPaths !== [] ? $runtimeConfig->projectPaths : $engineConfig->projectPaths,
            $normalize,
        );
        // Declared scope: what descent would have covered had the host narrowed nothing. It answers one
        // question only — is a declined hop the HOST's narrowing, which the reader can undo, or this
        // engine's own containment, which they cannot — and an empty list answers "no" to all of them.
        $declaredFilter = new ProjectFilter($engineConfig->declaredPaths, $normalize);

        return new PhpStanTypeEngine(
            adapter: $adapter,
            config: $engineConfig,
            translator: $translator,
            fileAnalyzer: $fileAnalyzer,
            projectFilter: $projectFilter,
            classMetadataFactory: new ClassMetadataFactory,
            appFilter: $appFilter,
            declaredFilter: $declaredFilter,
            walks: $walks,
        );
    }
}
