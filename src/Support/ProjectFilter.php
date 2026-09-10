<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Support;

use Closure;

/**
 * Whether a file sits under one of a set of directories. What that ANSWERS is the set it was built with,
 * and the engine builds three of them (`PhpStanEngineFactory`), because a build asks three different
 * questions about a file and they have different answers for the same one.
 *
 * The DESCEND scope is `engine.project_paths`, and this gate — not depth — does the real containment of
 * interprocedural descent: it stops at the first method declared outside it even when the receiver is the
 * application's own class (`Model::findOrFail` on an `App\Models\User`). The APPLICATION scope is every
 * source root the adapter primes, which is where the reads live: whether a declaration is the
 * application's own, and so whether a reader owns the edit a diagnostic asks for. The DECLARED scope is
 * what descent would have covered had the host narrowed nothing: the only thing that tells a hop the
 * reader's own setting closed from one this engine closes for every application, and so the only thing
 * a narrowed-scope notice may be sized against. Vendor is in none of the three, so nothing about
 * containment loosens by asking a wider one.
 *
 * @internal
 */
final class ProjectFilter
{
    /** @var list<string> normalised directory prefixes of whichever scope this filter was built for */
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
