<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Metadata;

use Docuccino\Core\Inference\DType\CallableT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Support\EnumCases;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Maps a native PHP `ReflectionType` to a {@see DType} for {@see ClassMetadataFactory}, which resolves
 * property types by reflection alone. Stays literal — docblock `@var` refinement is an integration concern.
 *
 * @internal
 */
final class NativeTypeMapper
{
    public function map(?ReflectionType $type): DType
    {
        if ($type === null) {
            return new UnknownT('no declared type');
        }

        if ($type instanceof ReflectionUnionType) {
            return UnionT::of(array_values(array_map(fn (ReflectionType $t): DType => $this->map($t), $type->getTypes())));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return IntersectionT::of(array_values(array_map(fn (ReflectionType $t): DType => $this->map($t), $type->getTypes())));
        }

        if ($type instanceof ReflectionNamedType) {
            $mapped = $this->named($type->getName());

            if ($type->allowsNull() && $type->getName() !== 'null' && $type->getName() !== 'mixed') {
                return UnionT::of([$mapped, new NullT]);
            }

            return $mapped;
        }

        return new UnknownT('unsupported reflection type');
    }

    private function named(string $name): DType
    {
        return match ($name) {
            'int' => ScalarT::int(),
            'string' => ScalarT::string(),
            'float' => ScalarT::float(),
            'bool', 'true', 'false' => ScalarT::bool(),
            'null' => new NullT,
            'void' => new VoidT,
            'never' => new NeverT,
            'callable', 'Closure' => new CallableT,
            'array' => new UnknownT('untyped array'),
            'iterable' => new UnknownT('iterable'),
            'object' => new UnknownT('object'),
            'mixed' => new UnknownT('mixed'),
            'self', 'static', 'parent' => new UnknownT($name),
            default => enum_exists($name) ? new EnumT($name, EnumCases::names($name)) : new ClassT($name),
        };
    }
}
