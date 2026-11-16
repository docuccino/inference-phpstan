<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Keeping;

/** Helpers a {@see Kept} is handed to. */
final class KeptReader
{
    public static function read(Kept $kept, int $floor): int
    {
        return max($floor, $kept->status, $kept->counted($floor));
    }

    public static function write(Kept $kept, int $code): void
    {
        $kept->status = $code;
    }

    public static function stamp(Kept $kept, int $floor): void
    {
        $kept->chained($floor);
    }

    public static function stampOnward(Kept $kept, int $floor): void
    {
        self::stamped($kept, $floor);
    }

    public static function stamped(Kept $kept, int $floor): Kept
    {
        return $kept->chained($floor);
    }

    public static function swap(Kept &$kept): void
    {
        $kept = new Kept;
    }

    public function inspect(Kept $kept, int $floor): int
    {
        return self::read($kept, $floor);
    }
}
