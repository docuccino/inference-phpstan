<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime\V2_2;

use Docuccino\Inference\PhpStan\Extensions\ResponseJsonReturnTypeExtension;
use Docuccino\Inference\PhpStan\Runtime\BootFailedException;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter as RuntimeAdapterContract;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use FilesystemIterator;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Parser\PathRoutingParser;
use PHPStan\Reflection\ReflectionProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * PHPStan 2.2.x / 2.3.x adapter. Everything fragile — container bootstrap,
 * manual `bootstrapFiles`, the dual parser-priming fix, `FileHelper`
 * normalisation, cwd management — lives here (design §2). All of it was proven
 * out by Spike A; every documented trap is honoured with a comment pointing at
 * it.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class RuntimeAdapter implements RuntimeAdapterContract
{
    private bool $booted = false;

    private ?NodeScopeResolver $nodeScopeResolver = null;

    private ?ScopeFactory $scopeFactory = null;

    private ?Parser $parser = null;

    private ?FileHelper $fileHelper = null;

    /** The `pathRoutingParser` service; only this concrete class declares `setAnalysedFiles()`. */
    private ?PathRoutingParser $pathRoutingParser = null;

    private ?ReflectionProvider $reflectionProvider = null;

    /**
     * The running analysed-file set (normalised). Grows only — a file is never
     * un-primed once analysed, so nothing gets routed back to `CleaningParser`.
     *
     * @var array<string, true>
     */
    private array $analysedFiles = [];

    public function __construct(private readonly RuntimeConfig $config) {}

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        try {
            $this->bootContainer();
        } catch (BootFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BootFailedException(
                sprintf('Failed to boot the PHPStan/Larastan container: %s', $e->getMessage()),
                previous: $e,
            );
        }

        $this->booted = true;
        $this->prime($this->discoverProjectFiles());
    }

    private function bootContainer(): void
    {
        $larastanNeon = $this->config->resolvedLarastanNeon();
        if (! is_file($larastanNeon)) {
            throw new BootFailedException(sprintf('Larastan extension.neon not found at %s', $larastanNeon));
        }

        if (! is_dir($this->config->tmpDir)) {
            mkdir($this->config->tmpDir, 0755, true);
        }

        $generatedNeon = $this->writeGeneratedNeon($larastanNeon);

        // Larastan's bootstrap boots the Laravel app from getcwd()/bootstrap/app.php,
        // so cwd must be the app root before the container is built (Spike A #3).
        chdir($this->config->projectRoot);

        $factory = new ContainerFactory($this->config->projectRoot);
        $container = $factory->create(
            $this->config->tmpDir,
            [$generatedNeon],
            [],
            $this->config->resolvedAutoloaderPaths(),
        );

        // bootstrapFiles are NOT auto-run by a raw ContainerFactory embed; the CLI
        // runs them via CommandHelper. Without this, Larastan never boots the app
        // and LARAVEL_VERSION stays undefined (Spike A #1).
        $bootstrapFiles = $container->getParameter('bootstrapFiles');
        if (is_array($bootstrapFiles)) {
            foreach ($bootstrapFiles as $bootstrapFile) {
                if (is_string($bootstrapFile)) {
                    (static function (string $file): void {
                        require_once $file;
                    })($bootstrapFile);
                }
            }
        }

        $this->nodeScopeResolver = $container->getByType(NodeScopeResolver::class);
        $this->scopeFactory = $container->getByType(ScopeFactory::class);
        $this->fileHelper = $container->getByType(FileHelper::class);
        $this->reflectionProvider = $container->getByType(ReflectionProvider::class);

        $parser = $container->getService('defaultAnalysisParser');
        $pathRoutingParser = $container->getService('pathRoutingParser');
        // The parser router is exactly the fragile internal plumbing this single
        // per-minor adapter exists to confine (design §2) — not BC-covered.
        // @phpstan-ignore phpstanApi.class
        if (! $parser instanceof Parser || ! $pathRoutingParser instanceof PathRoutingParser) {
            throw new BootFailedException('PHPStan parser services are not the expected type.');
        }
        $this->parser = $parser;
        $this->pathRoutingParser = $pathRoutingParser;
    }

    public function prime(array $files): void
    {
        $helper = $this->fileHelper();
        foreach ($files as $file) {
            $this->analysedFiles[$helper->normalizePath($file)] = true;
        }

        $normalised = array_keys($this->analysedFiles);
        sort($normalised);

        // The Spike A body-stripping fix: prime BOTH services. A file absent from
        // the pathRoutingParser's set is routed to CleaningParser (bodies deleted),
        // making MethodReturnStatementsNode silently report zero returns/throws.
        // setAnalysedFiles is internal plumbing this adapter is licensed to touch.
        // @phpstan-ignore phpstanApi.method
        $this->pathRoutingParser()->setAnalysedFiles($normalised);
        $this->nodeScopeResolver()->setAnalysedFiles($normalised);
    }

    public function processFile(string $file, callable $callback): void
    {
        // Prime before the first parse so CachedParser caches a body-preserving
        // copy (Spike C trap #1 — the body-stripping trap one level deeper).
        $this->prime([$file]);

        $nodes = $this->parser()->parseFile($file);
        $scope = $this->scopeFactory()->create(ScopeContext::create($file));
        $this->nodeScopeResolver()->processNodes($nodes, $scope, $callback);
    }

    public function normalize(string $file): string
    {
        return $this->fileHelper()->normalizePath($file);
    }

    public function reflectionProvider(): ReflectionProvider
    {
        return $this->reflectionProvider ?? throw new BootFailedException('Adapter not booted.');
    }

    /**
     * @return list<string>
     */
    private function discoverProjectFiles(): array
    {
        $files = [];
        foreach ($this->config->projectPaths as $path) {
            if (is_file($path) && str_ends_with($path, '.php')) {
                $files[] = $path;

                continue;
            }
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function writeGeneratedNeon(string $larastanNeon): string
    {
        $includes = "    - {$larastanNeon}\n";
        if ($this->config->userNeon !== null && is_file($this->config->userNeon)) {
            $includes .= "    - {$this->config->userNeon}\n";
        }

        $stubFiles = '';
        foreach ($this->stubFiles() as $stubFile) {
            $stubFiles .= "        - {$stubFile}\n";
        }
        $extensionClass = ResponseJsonReturnTypeExtension::class;

        $neon = <<<NEON
            includes:
            {$includes}
            parameters:
                level: 9
                paths: []
                tmpDir: {$this->config->tmpDir}
                phpVersion: {$this->config->phpVersion}
                stubFiles:
            {$stubFiles}
            services:
                -
                    class: {$extensionClass}
                    tags:
                        - phpstan.broker.dynamicMethodReturnTypeExtension
            NEON;

        $generatedNeon = $this->config->tmpDir.'/docuccino.neon';
        file_put_contents($generatedNeon, $neon);

        return $generatedNeon;
    }

    /**
     * Every bundled `.stub` file (payload/status on JsonResponse, the API-resource conditional +
     * collection typing …), sorted for a deterministic generated neon. Adding a stub to the `stubs/`
     * directory registers it — no per-stub wiring here.
     *
     * @return list<string>
     */
    private function stubFiles(): array
    {
        $stubs = glob(dirname(__DIR__, 3).'/stubs/*.stub');
        if ($stubs === false) {
            return [];
        }

        sort($stubs);

        return $stubs;
    }

    private function nodeScopeResolver(): NodeScopeResolver
    {
        return $this->nodeScopeResolver ?? throw new BootFailedException('Adapter not booted.');
    }

    private function scopeFactory(): ScopeFactory
    {
        return $this->scopeFactory ?? throw new BootFailedException('Adapter not booted.');
    }

    private function parser(): Parser
    {
        return $this->parser ?? throw new BootFailedException('Adapter not booted.');
    }

    private function pathRoutingParser(): PathRoutingParser
    {
        return $this->pathRoutingParser ?? throw new BootFailedException('Adapter not booted.');
    }

    private function fileHelper(): FileHelper
    {
        return $this->fileHelper ?? throw new BootFailedException('Adapter not booted.');
    }
}
