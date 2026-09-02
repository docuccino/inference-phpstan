<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Inference\PhpStan\Support\ProjectFilter;

/**
 * The concrete method a call dispatches to: declaring class, method name, file. Project-vs-vendor is
 * {@see ProjectFilter}'s call, not stored here.
 *
 * `file` is where the declaring CLASS is written, which is where a reader goes to find the body — but PHP
 * reports a trait-imported method as the using class's, so it is not always where the body was WRITTEN.
 * {@see writtenIn()} is the second file, and the two are the same for every method but a trait's.
 *
 * @internal
 */
final readonly class Callee
{
    public function __construct(
        public string $class,
        public string $method,
        public string $file,
        private ?string $declarationFile = null,
    ) {}

    public function key(): string
    {
        return $this->class.'::'.$this->method;
    }

    /**
     * The file the method's own body is written in. A dependency wherever the body decided something — the
     * `@throws` on a trait's shared guard clause is written there and read here, and a fragment naming only
     * the using class's file stays warm when that guard starts throwing something else.
     */
    public function writtenIn(): string
    {
        return $this->declarationFile ?? $this->file;
    }
}
