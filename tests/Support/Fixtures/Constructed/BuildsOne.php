<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Constructed;

use ArrayObject as Bag;
use Docuccino\Inference\PhpStan\Extensions\ConstructedReturn;
use SplObjectStorage;

/**
 * Methods whose bodies construct what they return — or do not — in each way a body can, read from source
 * by {@see ConstructedReturn}.
 */
abstract class BuildsOne
{
    use BuildsFromTrait;

    public function one(): object
    {
        return new SplObjectStorage;
    }

    public function imported(): object
    {
        return new Bag;
    }

    public function onEveryPath(bool $flag): object
    {
        if ($flag) {
            return new SplObjectStorage;
        }

        return new SplObjectStorage;
    }

    public function twoClasses(bool $flag): object
    {
        if ($flag) {
            return new SplObjectStorage;
        }

        return new Bag;
    }

    public function notConstructed(): object
    {
        return $this;
    }

    public function lateStatic(): static
    {
        return new static;
    }

    /** @param  class-string  $class */
    public function dynamic(string $class): object
    {
        return new $class;
    }

    public function anonymous(): object
    {
        return new class {};
    }

    public function nestedReturns(): object
    {
        $count = static function (): int {
            return 1;
        };
        $helper = new class
        {
            public function make(): object
            {
                return new \stdClass;
            }
        };

        return new SplObjectStorage;
    }

    public function returnsNothing(): void {}

    #[\Deprecated]
    public function attributed(): object
    {
        return new Bag;
    }

    abstract public function declaredOnly(): object;
}

/** A second class in the same file with a method of the same name, which must not be mistaken for it. */
final class BuildsOther
{
    public function one(): object
    {
        return new \ArrayIterator;
    }
}
