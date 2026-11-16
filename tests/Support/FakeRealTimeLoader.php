<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

/** Stands in for Laravel's `AliasLoader`: the namespace its real-time facades are named under. */
final class FakeRealTimeLoader
{
    /** @var string */
    protected static $facadeNamespace = '';

    public static function namespaceFacadesUnder(string $namespace): void
    {
        self::$facadeNamespace = $namespace;
    }

    public static function facadeNamespace(): string
    {
        return self::$facadeNamespace;
    }
}
