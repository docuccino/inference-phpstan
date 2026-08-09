<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Inference\PhpStan\Support\ProjectFilter;

/**
 * The concrete method a call node dispatches to: declaring class, method name,
 * and the file it lives in. Whether it is project or vendor code is decided by
 * {@see ProjectFilter}, not stored here.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final readonly class Callee
{
    public function __construct(
        public string $class,
        public string $method,
        public string $file,
    ) {}

    public function key(): string
    {
        return $this->class.'::'.$this->method;
    }
}
