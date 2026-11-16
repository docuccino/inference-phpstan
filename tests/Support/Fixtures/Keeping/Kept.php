<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Keeping;

use Closure;

/**
 * An object whose methods each touch its own state in one way, for the call that runs them to keep or not.
 * Never run: only read.
 */
class Kept extends KeptBase
{
    /** @var list<int> */
    public array $items = [];

    public string $data = '{}';

    public ?Kept $next = null;

    public function decoded(bool $assoc): mixed
    {
        return json_decode($this->data, $assoc);
    }

    public function counted(int $floor): int
    {
        return max($floor, count($this->items));
    }

    public function tested(string $why): bool
    {
        return $this instanceof KeptContract || $this->next?->status === 0;
    }

    public function delegated(int $floor): int
    {
        return $this->counted($floor) + self::counted($floor);
    }

    public function handedOn(int $floor): int
    {
        return KeptReader::read($this, $floor);
    }

    public function recursive(int $depth): int
    {
        return $depth > 0 ? $this->recursive($depth - 1) : $this->status;
    }

    public function deep(int $floor): int
    {
        return $this->deeper($floor);
    }

    public function deeper(int $floor): int
    {
        return $this->deeperStill($floor);
    }

    public function deeperStill(int $floor): int
    {
        return $this->deepest($floor);
    }

    public function deepest(int $floor): int
    {
        return $this->bottom($floor);
    }

    public function bottom(int $floor): int
    {
        return $this->counted($floor);
    }

    public function restated(int $code): void
    {
        $this->status = $code;
    }

    public function bumped(int $by): void
    {
        $this->status += $by;
    }

    public function incremented(int $by): void
    {
        $this->status++;
    }

    public function cleared(int $at): void
    {
        unset($this->items[$at]);
    }

    /** @param  array{int, string}  $pair */
    public function destructured(array $pair): void
    {
        [$this->status, $this->data] = $pair;
    }

    public function referenced(int $code): void
    {
        $slot = &$this->status;
        $slot = $code;
    }

    public function looped(int $value): void
    {
        foreach ($this->items as &$item) {
            $item = $value;
        }
    }

    /** @param  list<int>  $values */
    public function loopedInto(array $values): void
    {
        foreach ($values as $this->status) {
        }
    }

    public function sorted(int $flags): void
    {
        sort($this->items, $flags);
    }

    public function handedProperty(object $other): void
    {
        $other->take($this->items);
    }

    public function returned(int $code): self
    {
        return $this;
    }

    public function chained(int $floor): static
    {
        $this->counted($floor);

        return $this;
    }

    public function chainedDropped(int $floor): void
    {
        $this->chained($floor);
    }

    public function chainedKept(int $code): void
    {
        $alias = $this->chained($code);
        $alias->restated($code);
    }

    public function returnedLater(int $floor): Closure
    {
        return function (): static {
            return $this;
        };
    }

    public function delegatedChange(int $code): void
    {
        $this->restated($code);
    }

    public function parentChange(int $code): void
    {
        parent::reset($code);
    }

    public function handedToWriter(int $code): void
    {
        KeptReader::write($this, $code);
    }

    public function aliased(int $code): void
    {
        $alias = $this;
        $alias->restated($code);
    }

    public function encoded(int $flags): string
    {
        return (string) json_encode($this, $flags);
    }

    public function callable(int $code): Closure
    {
        return $this->restated(...);
    }

    /** @param  array<mixed>  $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}
