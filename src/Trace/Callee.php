<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Docuccino\Core\Support\Fqcn;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node\Name;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * The concrete method a call dispatches to: declaring class, method name, file — or the function it calls,
 * which has no class and carries its fully-qualified name as `method`. Project-vs-vendor is
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
        public ?string $class,
        public string $method,
        public string $file,
        private ?string $declarationFile = null,
    ) {}

    /** A function's key is its name and parentheses, which no `Class::method` can spell. */
    public function key(): string
    {
        return $this->class === null ? $this->method.'()' : $this->class.'::'.$this->method;
    }

    /**
     * Its parameters in declaration order: the method's own, or the function's, found by its name.
     *
     * @return list<ParameterReflection>
     */
    public function parameters(ReflectionProvider $provider): array
    {
        if ($this->class === null) {
            $name = new Name\FullyQualified($this->method);

            return $provider->hasFunction($name, null) ? $provider->getFunction($name, null)->getVariants()[0]->getParameters() : [];
        }

        if (! $provider->hasClass($this->class)) {
            return [];
        }
        $class = $provider->getClass($this->class);

        return $class->hasNativeMethod($this->method) ? $class->getNativeMethod($this->method)->getVariants()[0]->getParameters() : [];
    }

    /** What a frame through this callee is labelled. */
    public function label(): string
    {
        return $this->class === null ? $this->method : Fqcn::short($this->class).'::'.$this->method;
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
