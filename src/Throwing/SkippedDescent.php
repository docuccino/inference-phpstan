<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\SourceLocation;

/**
 * One call whose body the descend scope would not let this build open, even though the application
 * declares that file: which callee, where it is called, and the file descent stopped short of.
 *
 * The point it was recorded at is what makes it worth a reader's time. Descent is only reached for a
 * throw point PHPStan flagged with nothing but a bare `Throwable` in it, so declining the hop drops the
 * point altogether: whatever that callee raises is documented nowhere, and a response missing outright
 * is the one outcome worse than a placeholder status.
 *
 * The sentence stays in the conditional for a measured reason. Widening the scope reads the body and
 * sometimes finds nothing publishable — 3 of 7 firings on the fixture corpus recovered a throw and 2 a
 * response — so what the record can promise is that nothing was LOOKED at, never that something was
 * lost. A notice that says "an error is missing" where the callee turns out to raise none is the kind
 * that teaches people to skip the channel.
 *
 * Every record kept is actionable by construction: {@see SkippedDescents::record()} keeps only a file
 * the DECLARED scope would have opened, so removing the setting that narrowed descent really does
 * reach it, and that setting is the same author's.
 *
 * @internal
 */
final readonly class SkippedDescent
{
    /**
     * @param  string  $calleeClass  the class declaring the method descent stopped at
     * @param  string  $calleeMethod  its method name
     * @param  string  $calleeFile  the file whose body was not read
     * @param  SourceLocation  $site  where the call is written
     */
    public function __construct(
        public string $calleeClass,
        public string $calleeMethod,
        public string $calleeFile,
        public SourceLocation $site,
    ) {}

    /**
     * One notice per callee and call site, not one per analysed path that reaches it — a helper called
     * from two branches of one action is one thing to go and fix.
     */
    public function key(): string
    {
        return $this->calleeFile."\0".$this->calleeClass."\0".$this->calleeMethod
            ."\0".$this->site->file."\0".$this->site->line;
    }

    /** The sentence the notice is written from: what was not read, where it is called, and the cost. */
    public function sentence(): string
    {
        return sprintf(
            '%s::%s() can throw and is called at %s:%d, and its body was not read: %s is outside the directories inference descends into, so an error response behind that call would be missing from this document.',
            $this->calleeClass,
            $this->calleeMethod,
            $this->site->file,
            $this->site->line,
            $this->calleeFile,
        );
    }
}
