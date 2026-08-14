<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Closure;
use Countable;
use Docuccino\Inference\PhpStan\Metadata\NativeTypeMapper;
use LogicException;
use Stringable;

/**
 * One member per native type shape {@see NativeTypeMapper} maps, so the mapper can be driven with REAL
 * `ReflectionType` objects rather than hand-built doubles. Return types carry the shapes PHP does not allow
 * on a property (`void`, `never`, `null`, `static`, `parent`). Autoloaded (not inline in a test file) so it
 * is visible across Paratest processes; only ever reflected, never instantiated.
 */
final class NativeTypeProbe extends NativeTypeProbeBase
{
    public int $int = 0;

    public string $string = '';

    public float $float = 0.0;

    public bool $bool = false;

    public true $true = true;

    public false $false = false;

    public array $array = [];

    public iterable $iterable = [];

    public object $object;

    public mixed $mixed = null;

    public Closure $closure;

    public Colour $enum = Colour::Red;

    public NativeTypeProbeBase $class;

    public self $self;

    public ?string $nullableString = null;

    public ?Colour $nullableEnum = null;

    public int|string $union = 0;

    public Countable&Stringable $intersection;

    public $untyped;

    public function callable(): callable
    {
        return static fn (): int => 0;
    }

    public function void(): void {}

    public function never(): never
    {
        throw new LogicException('only ever reflected');
    }

    public function null(): null
    {
        return null;
    }

    public function static(): static
    {
        return $this;
    }

    public function parent(): parent
    {
        return $this;
    }
}
