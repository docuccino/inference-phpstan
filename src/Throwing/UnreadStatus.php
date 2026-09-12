<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\SourceLocation;

/**
 * One throw whose HTTP status this build could not read: which exception, where the throw is written,
 * and which fold gave up on it.
 *
 * `inProjectCode` is the actionability fact, and it is the file the FOLD READ — not where the exception
 * class happens to be declared (docs/design/inference-embedding.md §6). Whichever file that is, the
 * question asked of it is whether the APPLICATION owns it, so every source root the build primes counts and
 * the narrower set interprocedural descent is bounded by does not: a reader owns a modular PSR-4 root as
 * much as `app/`, and a notice silenced by which directory the code sits in is one nobody asked for.
 *
 * @internal
 */
final readonly class UnreadStatus
{
    public function __construct(
        public string $exceptionFqcn,
        public UnreadStatusReason $reason,
        public SourceLocation $site,
        public bool $inProjectCode,
    ) {}

    /**
     * Whether a notice about this would address somebody who can act. Nothing about the reason: the same
     * fold gives up the same way in a package-shipped action, where the edit it names is one the reader
     * does not own.
     */
    public function isActionable(): bool
    {
        return $this->inProjectCode && $this->reason->remedy() !== null;
    }

    /**
     * One notice per exception, reason and throw site — not one per analysed path that reaches it, which
     * would report the same line once per descent branch.
     */
    public function key(): string
    {
        return $this->exceptionFqcn."\0".$this->reason->value."\0".$this->site->file."\0".$this->site->line;
    }

    /**
     * The sentence the notice is written from: what could not be read, where, and why.
     *
     * It reports what the FOLD came back with rather than what the error ends up documented as: the
     * engine cannot see the document, and `#[Response(status: …)]` answers the same node
     * (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it never reads").
     */
    public function sentence(): string
    {
        return sprintf(
            '%s is thrown at %s:%d with no status this build could read: %s. Inference recovered no status for the error, so a later tier decides the one it is filed under.',
            $this->exceptionFqcn,
            $this->site->file,
            $this->site->line,
            $this->reason->because(),
        );
    }
}
