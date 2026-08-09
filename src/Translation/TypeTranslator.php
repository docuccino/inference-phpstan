<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Translation;

use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\CallableT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\IntersectionT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Support\EnumCases;
use PHPStan\Type\Accessory\AccessoryType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use Throwable;

/**
 * Translates a PHPStan `Type` into the closed {@see DType} set (docs/design/inference-embedding.md §5).
 * Translation is eager, so results serialize across worker and cache boundaries; class *expansion* stays
 * lazy behind `classMetadata()`.
 *
 * Detection goes through PHPStan's BC-stable accessors (`getConstantStrings()`, `isArray()`, …) rather than
 * `instanceof` on the type hierarchy, per PHPStan's own guidance. The handful of remaining `instanceof`s
 * decompose union/intersection/generic/accessory types, which no accessor exposes; each carries its own
 * line-scoped ignore. No booted container is needed, which is why the unit tests can be table-driven.
 *
 * @internal
 */
final class TypeTranslator
{
    public function translate(Type $type, ?TranslationBudget $budget = null): DType
    {
        $budget ??= new TranslationBudget;

        if ($budget->exhausted()) {
            return new UnknownT('translation depth budget exhausted');
        }

        if ($type->isNull()->yes()) {
            return new NullT;
        }
        if ($type->isVoid()->yes()) {
            return new VoidT;
        }
        if ($type instanceof NeverType) {
            return new NeverT;
        }

        // Constant scalars → literals, before the general scalar checks below.
        $literal = $this->constantLiteral($type);
        if ($literal !== null) {
            return $literal;
        }

        // Constant array → shape, before the general array handling below.
        $constantArrays = $type->getConstantArrays();
        if (count($constantArrays) === 1) {
            return $this->translateConstantArray($constantArrays[0], $budget);
        }

        if ($type instanceof UnionType) {
            return UnionT::of(array_map(
                fn (Type $t): DType => $this->translate($t, $budget->descend()),
                $type->getTypes(),
            ));
        }

        // @phpstan-ignore phpstanApi.instanceofType (structural decomposition; no accessor exposes members)
        if ($type instanceof IntersectionType) {
            return $this->translateIntersection($type, $budget);
        }

        if ($type instanceof TemplateType) {
            return $this->translate($type->getBound(), $budget->descend());
        }

        // @phpstan-ignore phpstanApi.instanceofType (only GenericObjectType carries resolved type args)
        if ($type instanceof GenericObjectType) {
            return $this->translateObject($type->getClassName(), array_values($type->getTypes()), $budget);
        }
        $objectClasses = $type->getObjectClassNames();
        if (count($objectClasses) === 1) {
            return $this->translateObject($objectClasses[0], [], $budget);
        }

        if ($type->isArray()->yes()) {
            $value = $this->translate($type->getIterableValueType(), $budget->descend());
            if ($type->isList()->yes()) {
                return new ListT($value);
            }

            return new MapT($this->translate($type->getIterableKeyType(), $budget->descend()), $value);
        }

        if ($type->isCallable()->yes()) {
            return new CallableT;
        }

        if ($type->isInteger()->yes()) {
            return ScalarT::int();
        }
        if ($type->isString()->yes()) {
            return ScalarT::string();
        }
        if ($type->isFloat()->yes()) {
            return ScalarT::float();
        }
        if ($type->isBoolean()->yes()) {
            return ScalarT::bool();
        }

        return new UnknownT($this->describe($type));
    }

    private function constantLiteral(Type $type): ?LiteralT
    {
        $strings = $type->getConstantStrings();
        if (count($strings) === 1) {
            return new LiteralT($strings[0]->getValue());
        }

        if ($type->isConstantScalarValue()->yes()) {
            $values = $type->getConstantScalarValues();
            if (count($values) === 1 && is_scalar($values[0])) {
                return new LiteralT($values[0]);
            }
        }

        return null;
    }

    private function translateConstantArray(ConstantArrayType $type, TranslationBudget $budget): DType
    {
        $keyTypes = $type->getKeyTypes();
        $valueTypes = $type->getValueTypes();
        $optionalKeys = $type->getOptionalKeys();

        $fields = [];
        foreach ($keyTypes as $i => $keyType) {
            // Constant-array keys are always ConstantInteger|ConstantString, so getValue() lands in
            // ArrayShapeField's int|string key domain.
            $key = $keyType->getValue();
            $valueType = $valueTypes[$i] ?? null;
            $fields[] = new ArrayShapeField(
                $key,
                $valueType instanceof Type ? $this->translate($valueType, $budget->descend()) : new UnknownT('missing value type'),
                in_array($i, $optionalKeys, true),
            );
        }

        return new ArrayShapeT($fields, $type->isList()->yes());
    }

    private function translateIntersection(IntersectionType $type, TranslationBudget $budget): DType
    {
        // Accessory types (non-empty-string, has-offset, …) refine but aren't documentable shapes, so drop
        // them; a single survivor collapses to itself.
        $survivors = [];
        foreach ($type->getTypes() as $member) {
            // @phpstan-ignore phpstanApi.interface (accessory detection has no BC-stable accessor)
            if ($member instanceof AccessoryType) {
                continue;
            }
            $survivors[] = $this->translate($member, $budget->descend());
        }

        if ($survivors === []) {
            return new UnknownT($this->describe($type));
        }

        return IntersectionT::of($survivors);
    }

    /**
     * @param  list<Type>  $typeArgs
     */
    private function translateObject(string $className, array $typeArgs, TranslationBudget $budget): DType
    {
        if (enum_exists($className)) {
            return new EnumT($className, EnumCases::names($className));
        }

        return new ClassT(
            $className,
            array_map(fn (Type $t): DType => $this->translate($t, $budget->descend()), $typeArgs),
        );
    }

    private function describe(Type $type): string
    {
        try {
            return $type->describe(VerbosityLevel::typeOnly());
        } catch (Throwable) {
            return 'unresolved type';
        }
    }
}
