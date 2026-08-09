<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Inference\TypeEngineBuilder;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;

/**
 * This package's entry point for a host adapter: core's {@see TypeEngineBuilder} seam over
 * {@see PhpStanEngineFactory::create()}. An adapter names this class by string and probes for it, so
 * the package stays an optional dev-only install — nothing in an adapter imports the engine.
 */
final readonly class PhpStanTypeEngineBuilder implements TypeEngineBuilder
{
    public function __construct(
        private PhpStanEngineFactory $factory = new PhpStanEngineFactory,
    ) {}

    public function build(
        string $projectRoot,
        string $tmpDir,
        string $vendorPath,
        array $primePaths,
        array $descendPaths,
    ): TypeEngine {
        $runtime = new RuntimeConfig(
            projectRoot: $projectRoot,
            tmpDir: $tmpDir,
            phpVersion: PHP_VERSION_ID,
            projectPaths: $primePaths,
        );

        return $this->factory->create($runtime, EngineConfig::forProjectWithVendor($vendorPath, ...$descendPaths));
    }
}
