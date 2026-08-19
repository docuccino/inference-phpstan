<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapterFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;

/**
 * A pass-through {@see RuntimeAdapter} that counts `processFile()` per normalised file. Substituted into a
 * real booted engine through a {@see RuntimeAdapterFactory} subclass, so a fixture test can assert how many
 * live walks a file cost — the one observable that fails if the recorder stops being shared between the
 * method harvest and the traces.
 */
final class CountingRuntimeAdapter implements RuntimeAdapter
{
    /**
     * Live passes per normalised file.
     *
     * @var array<string, int>
     */
    public array $passes = [];

    public function __construct(private readonly RuntimeAdapter $inner) {}

    public function boot(): void
    {
        $this->inner->boot();
    }

    public function prime(array $files): void
    {
        $this->inner->prime($files);
    }

    public function processFile(string $file, callable $callback): void
    {
        $key = $this->inner->normalize($file);
        $this->passes[$key] = ($this->passes[$key] ?? 0) + 1;

        $this->inner->processFile($file, $callback);
    }

    public function analysedFileCount(): int
    {
        return $this->inner->analysedFileCount();
    }

    public function normalize(string $file): string
    {
        return $this->inner->normalize($file);
    }

    public function stableScope(Scope $scope): Scope
    {
        return $this->inner->stableScope($scope);
    }

    public function reflectionProvider(): ReflectionProvider
    {
        return $this->inner->reflectionProvider();
    }
}
