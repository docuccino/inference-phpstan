<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Inference\PhpStan\Support\ProjectFilter;

/**
 * The concrete method a call dispatches to: declaring class, method name, file. Project-vs-vendor is
 * {@see ProjectFilter}'s call, not stored here.
 *
 * @internal
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
