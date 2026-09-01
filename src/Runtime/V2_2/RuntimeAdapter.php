<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Runtime\V2_2;

use Docuccino\Core\Support\GeneratedDirectory;
use Docuccino\Inference\PhpStan\Extensions\DataToResponseReturnTypeExtension;
use Docuccino\Inference\PhpStan\Extensions\DataTransformReturnTypeExtension;
use Docuccino\Inference\PhpStan\Extensions\ResponseJsonReturnTypeExtension;
use Docuccino\Inference\PhpStan\Runtime\BootFailedException;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter as RuntimeAdapterContract;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use FilesystemIterator;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
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
 * PHPStan 2.2.x / 2.3.x adapter. Everything not covered by PHPStan's BC promise lives here — container
 * bootstrap, manual `bootstrapFiles`, dual parser priming, `FileHelper` normalisation, cwd management — so
 * a new minor needs one new adapter and nothing else. See docs/design/inference-embedding.md §2.
 *
 * The `V2_2` namespace is the minor this adapter was written against, not the range it serves: 2.3 needed
 * nothing new, so it lives here too. Adding 2.4 is one of two changes — if the internal surface above
 * still holds, widen `RuntimeAdapterFactory::SUPPORTED` (and the composer constraint) and leave this class
 * alone; if it moved, add a `V2_4\RuntimeAdapter` and point the factory's new entry at it. Renaming this
 * namespace per minor buys nothing and costs every import.
 *
 * @internal
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
     * The running analysed-file set (normalised). Grows only — nothing is ever un-primed, so no file gets
     * routed back to `CleaningParser`.
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

        GeneratedDirectory::ensure($this->config->tmpDir);

        $generatedNeon = $this->writeGeneratedNeon($larastanNeon);

        // Larastan boots the app from getcwd()/bootstrap/app.php — cwd must be the app root first.
        chdir($this->config->projectRoot);

        $factory = new ContainerFactory($this->config->projectRoot);
        $container = $factory->create(
            $this->config->tmpDir,
            [$generatedNeon],
            [],
            $this->config->resolvedAutoloaderPaths(),
        );

        // A raw ContainerFactory embed doesn't run bootstrapFiles (the CLI does it via CommandHelper).
        // Skip this and Larastan never boots the app: `Undefined constant LARAVEL_VERSION`.
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
        // The parser router isn't BC-covered — exactly the plumbing this per-minor adapter confines.
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
        $grew = false;
        foreach ($files as $file) {
            $normalisedFile = $helper->normalizePath($file);
            if (! isset($this->analysedFiles[$normalisedFile])) {
                $this->analysedFiles[$normalisedFile] = true;
                $grew = true;
            }
        }

        // Both setters just replace their set, so re-sending an unchanged one changes nothing —
        // skip it. processFile() primes per file after boot() primed the project, so on a large app
        // almost every call lands here and would otherwise re-sort the whole set for nothing.
        if (! $grew) {
            return;
        }

        $normalised = array_keys($this->analysedFiles);
        sort($normalised);

        // Prime BOTH services. A file missing from the pathRoutingParser's set goes to CleaningParser,
        // which deletes method bodies, and MethodReturnStatementsNode then silently reports zero returns.
        // @phpstan-ignore phpstanApi.method
        $this->pathRoutingParser()->setAnalysedFiles($normalised);
        $this->nodeScopeResolver()->setAnalysedFiles($normalised);
    }

    public function processFile(string $file, callable $callback): void
    {
        // Prime before the first parse, or CachedParser caches a body-stripped copy for good.
        $this->prime([$file]);

        $nodes = $this->parser()->parseFile($file);
        $scope = $this->scopeFactory()->create(ScopeContext::create($file));
        $this->nodeScopeResolver()->processNodes($nodes, $scope, $callback);
    }

    public function analysedFileCount(): int
    {
        return count($this->analysedFiles);
    }

    public function normalize(string $file): string
    {
        return $this->fileHelper()->normalizePath($file);
    }

    public function stableScope(Scope $scope): Scope
    {
        // A walk hands a callback a scope of its own making, and which one moved inside 2.2: up to 2.2.9 it
        // was a fiber scope, whose getType() suspends and so answers only while its fiber lives; from 2.2.10
        // it is a node-callback scope that answers a retained ask directly. `toMutatingScope()` is the read
        // both eras agree on — the captured scope in the first, the scope itself in the second — so the call
        // is the whole body and no version branch is needed. It is asked of the `Scope` INTERFACE, which is
        // the point: the interface carries `@api` and has declared this method across the minor, while the
        // concrete class only one era had did not survive a patch release. Naming no concrete scope class is
        // what keeps this compiling on both. AnalyserDriftTest fails if either half of that stops holding.
        return $scope->toMutatingScope();
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
        $includes = '    - '.self::neonString($larastanNeon)."\n";
        if ($this->config->userNeon !== null && is_file($this->config->userNeon)) {
            $includes .= '    - '.self::neonString($this->config->userNeon)."\n";
        }

        $stubFiles = '';
        foreach ($this->stubFiles() as $stubFile) {
            $stubFiles .= '        - '.self::neonString($stubFile)."\n";
        }
        // Every dynamic return-type extension the engine ships. Registration is uniform, so adding one
        // here is the whole wiring.
        $services = '';
        foreach ([
            ResponseJsonReturnTypeExtension::class,
            DataToResponseReturnTypeExtension::class,
            DataTransformReturnTypeExtension::class,
        ] as $extensionClass) {
            $services .= <<<NEON
                    -
                        class: {$extensionClass}
                        tags:
                            - phpstan.broker.dynamicMethodReturnTypeExtension

                NEON;
        }

        $tmpDir = self::neonString($this->config->tmpDir);

        $neon = <<<NEON
            includes:
            {$includes}
            parameters:
                level: 9
                paths: []
                tmpDir: {$tmpDir}
                phpVersion: {$this->config->phpVersion}
                stubFiles:
            {$stubFiles}
            services:
            {$services}
            NEON;

        $generatedNeon = $this->config->tmpDir.'/docuccino.neon';
        if (@file_put_contents($generatedNeon, $neon) === false) {
            throw new BootFailedException(sprintf(
                'Could not write the generated PHPStan config to %s — check that directory exists and is '
                .'writable by the user running the generator.',
                $generatedNeon,
            ));
        }

        return $generatedNeon;
    }

    /**
     * A path as a NEON single-quoted string. Unquoted, a path with a space is two tokens and one with a
     * `#` or `:` is something else entirely, and the container fails to boot on a config we wrote
     * ourselves. Single quotes are NEON's literal form — a backslash stays a backslash, which is what a
     * Windows path needs — and the only escape is doubling an apostrophe.
     */
    private static function neonString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Every bundled `.stub`, sorted so the generated neon is deterministic. Dropping a file into `stubs/`
     * registers it; there's no per-stub wiring.
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
