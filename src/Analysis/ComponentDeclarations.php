<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Attributes\ErrorComponent;
use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\SourceLocation;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads the `#[ErrorComponent]` a method declares for the body it answers with.
 *
 * The attribute is never instantiated: an argument list is all this needs, and reflecting an app class
 * must not depend on an attribute class being loadable in the analysed process. PHP resolves an
 * unoverridden method to the parent that declared it, so inheritance is free here — unlike the class
 * anchor, which has to walk. Total, like everything the engine exposes: an unreflectable class or a
 * malformed argument is no declaration.
 *
 * @internal
 */
final readonly class ComponentDeclarations
{
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {}

    public function on(string $class, string $method): ?ComponentDeclaration
    {
        try {
            if (! $this->reflectionProvider->hasClass($class)) {
                return null;
            }

            $native = $this->reflectionProvider->getClass($class)->getNativeReflection();

            return $native->hasMethod($method) ? self::onMethod($native->getMethod($method)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The file a method is written in — where an `#[ErrorComponent]` for it would have to appear, which is
     * not the class's own file for an inherited or a trait-imported one. The caller keys the ABSENCE of a
     * declaration on this. Total, and it never autoloads, like everything else here.
     */
    public function fileFor(string $class, string $method): ?string
    {
        try {
            if (! $this->reflectionProvider->hasClass($class)) {
                return null;
            }

            $native = $this->reflectionProvider->getClass($class)->getNativeReflection();
            $file = $native->hasMethod($method) ? $native->getMethod($method)->getFileName() : false;

            return $file === false ? null : $file;
        } catch (Throwable) {
            return null;
        }
    }

    /** The declaration a reflected method carries, reported against the class or trait that really wrote it. */
    public static function onMethod(ReflectionMethod $method): ?ComponentDeclaration
    {
        foreach ($method->getAttributes() as $attribute) {
            if ($attribute->getName() !== ErrorComponent::class) {
                continue;
            }

            $arguments = $attribute->getArguments();
            $name = $arguments[0] ?? $arguments['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }

            $file = $method->getFileName();
            $line = $method->getStartLine();

            return new ComponentDeclaration(
                $name,
                self::declaringSymbol($method),
                new SourceLocation($file === false ? '' : $file, $line === false ? null : $line),
            );
        }

        return null;
    }

    /**
     * Where the attribute is actually written. An unoverridden method belongs to the parent that declared
     * it, and reflection says so — but a trait-imported one is reported as the USING class's own while its
     * file stays the trait's, which would send a reader to a class whose file has no attribute in it. The
     * trait method sitting at that same file and line is the one that wrote it.
     */
    private static function declaringSymbol(ReflectionMethod $method): string
    {
        $class = $method->getDeclaringClass();
        $symbol = $class->getName().'::'.$method->getName();

        $file = $method->getFileName();
        if ($file === false || $file === $class->getFileName()) {
            return $symbol;
        }

        foreach (self::traits($class) as $trait) {
            foreach ($trait->getMethods() as $candidate) {
                if ($candidate->getFileName() === $file && $candidate->getStartLine() === $method->getStartLine()) {
                    return $trait->getName().'::'.$candidate->getName();
                }
            }
        }

        return $symbol;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<ReflectionClass<object>>
     */
    private static function traits(ReflectionClass $class): array
    {
        $traits = [];
        foreach ($class->getTraits() as $trait) {
            // A trait may use traits itself, and reports their methods as its own — so the innermost one
            // comes first, or the walk would stop at whichever trait merely passed the method along.
            foreach (self::traits($trait) as $nested) {
                $traits[] = $nested;
            }
            $traits[] = $trait;
        }

        return $traits;
    }
}
