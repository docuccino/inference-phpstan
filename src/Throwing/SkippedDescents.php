<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;

/**
 * What one analysis stopped short of because the descend scope excluded a file the application
 * declares, and the notices those records publish.
 *
 * The mirror of {@see UnreadStatuses} for the other end of the throw path: that one reports a response
 * the document carries with a status nobody read, this one a response the document may not carry at
 * all. Both are the analyser's PUBLISHED half, so both live outside {@see ThrowAnalyzer}, and both
 * speak at `info` — the recovery channel, where the document came out vaguer than the code and the
 * build says where. Bounding descent is a correct outcome rather than a defect, which is what keeps
 * this off the rung a severity gate fails on.
 *
 * The actionability filter is HERE, and it is the DECLARED scope — the descend scope the host would
 * have run with had nothing narrowed it. Only a hop that scope would have opened is kept, which makes
 * the remedy this publishes true: removing the setting really does reach the file named. The wider
 * "is this the application's own file" would not, because it also holds the roots descent declines by
 * design — a `autoload-dev` root is the application's own and is deliberately never walked into, so a
 * notice about one would fire on an unconfigured build and ask the reader to undo a decision they
 * never made. An empty declared scope keeps nothing: a host that names no yardstick gets no notices
 * rather than every notice.
 *
 * A narrowing that costs nothing still records nothing: the population is calls whose bodies really
 * were skipped, not scopes that happen to be narrow.
 *
 * @internal
 */
final class SkippedDescents
{
    /** @var array<string, SkippedDescent> by {@see SkippedDescent::key()} */
    private array $records = [];

    public function __construct(private readonly ProjectFilter $declared) {}

    /** Kept only where the declared scope would have opened this body — see the class note. */
    public function record(SkippedDescent $skipped): void
    {
        if (! $this->declared->isProjectFile($skipped->calleeFile)) {
            return;
        }

        $this->records[$skipped->key()] = $skipped;
    }

    /**
     * One notice per skipped call, in the records' own order rather than the order the analysis met
     * them, with the paths crossed into message form the way every other engine message is.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(MessagePaths $labels): array
    {
        $keys = array_keys($this->records);
        sort($keys);

        $diagnostics = [];
        foreach ($keys as $key) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'inference.descend-scope-narrowed',
                message: $labels->relative($this->records[$key]->sentence()),
                help: 'engine.project_paths in docuccino.yaml bounds how far inference walks, and this directory is outside it. Remove the key to descend into every PSR-4 root your composer.json declares under `autoload`, which is the default, or add this directory to the list you keep.',
            );
        }

        return $diagnostics;
    }
}
