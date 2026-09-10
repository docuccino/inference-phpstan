<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Inference\PhpStan\Throwing\KnownThrowers;
use Docuccino\Inference\PhpStan\Throwing\SkippedDescents;

/**
 * The engine's analysis knobs. The two descent bounds differ on purpose: inference/trace goes deeper (4)
 * than exception flow (3), because real throws cluster within 2 hops and the vendor gate does most of the
 * containment anyway.
 */
final readonly class EngineConfig
{
    /**
     * @param  list<string>  $projectPaths  directories treated as descendable project code
     * @param  string|null  $vendorPath  the app's vendor directory. A trace visitor following a callee's
     *                                   return type may descend into non-vendor app code outside
     *                                   $projectPaths (a modular Queries class) but never into this tree;
     *                                   null disables that widening.
     * @param  list<string>  $declaredPaths  the descend scope before the host narrowed it. Nothing walks
     *                                       by it: it decides only which declined hops are the HOST's
     *                                       narrowing and so worth a notice ({@see SkippedDescents}).
     *                                       Empty reports none, which is what a host that offers no
     *                                       yardstick gets.
     */
    public function __construct(
        public array $projectPaths,
        public KnownThrowers $knownThrowers,
        public int $traceDepth = 4,
        public int $throwDepth = 3,
        public int $fileBudget = 40,
        public ?string $vendorPath = null,
        public array $declaredPaths = [],
    ) {}

    public static function forProject(string ...$projectPaths): self
    {
        return new self(array_values($projectPaths), KnownThrowers::default());
    }

    public static function forProjectWithVendor(string $vendorPath, string ...$projectPaths): self
    {
        return new self(array_values($projectPaths), KnownThrowers::default(), vendorPath: $vendorPath);
    }
}
