<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

/** Stands in for Laravel's `AliasLoader`: the one instance, answering the aliases it is handed. */
final class FakeAliasLoader
{
    /** @var array<mixed> */
    public static array $aliases = [];

    public static function getInstance(): self
    {
        return new self;
    }

    /** @return array<mixed> */
    public function getAliases(): array
    {
        return self::$aliases;
    }
}
