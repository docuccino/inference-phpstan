<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Closure;

/**
 * Decides whether a file is project code (descendable) or vendor code (never
 * descended). The vendor gate — not raw depth — does the real containment of
 * interprocedural descent (Spike C): descent auto-stops at the first
 * vendor-declared method even when the receiver is a project class (e.g.
 * `Model::findOrFail` on an `App\Models\User`).
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class ProjectFilter
{
    /** @var list<string> normalised project directory prefixes */
    private array $prefixes;

    /** @var Closure(string): string */
    private Closure $normalizer;

    /**
     * @param  list<string>  $projectPaths
     * @param  callable(string): string  $normalizer  path normaliser matching PHPStan's (the adapter's)
     */
    public function __construct(array $projectPaths, callable $normalizer)
    {
        $this->normalizer = Closure::fromCallable($normalizer);
        $this->prefixes = array_map(
            fn (string $path): string => rtrim(($this->normalizer)($path), '/'),
            $projectPaths,
        );
    }

    public function isProjectFile(?string $file): bool
    {
        if ($file === null || $file === '') {
            return false;
        }

        $normalised = ($this->normalizer)($file);
        foreach ($this->prefixes as $prefix) {
            if ($normalised === $prefix || str_starts_with($normalised, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
