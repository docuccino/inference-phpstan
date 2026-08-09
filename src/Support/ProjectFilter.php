<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Closure;

/**
 * Project code (descendable) or vendor code (never descended). This gate, not depth, does the real
 * containment of interprocedural descent: it stops at the first vendor-declared method even when the
 * receiver is a project class (`Model::findOrFail` on an `App\Models\User`).
 *
 * @internal
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
