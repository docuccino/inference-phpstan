<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Inference\PhpStan\Analysis\AccessorKind;
use Docuccino\Inference\PhpStan\Analysis\EnumAccessorFolder;
use Docuccino\Inference\PhpStan\Analysis\FileAnalyzer;
use Docuccino\Inference\PhpStan\Analysis\ParamAccessor;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Enum\EnumCaseObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * The status an expression states where it is written: a constant integer, or a read on an enum case —
 * `TokenRefusal::Expired->status()`, `$fault->value`, or the `$reason->status()` a constructor hands its
 * parent, folded on the case a construction passes. An enum is how an application writes a reason and its status in one
 * place, so the number lives in a `match ($this)` arm rather than at the throw.
 *
 * Where the expression may be several cases, the answer is the one status they all read to, and none where
 * they disagree: a reason chosen at run time is whichever status that case maps to, which no single number
 * states. A type holding anything besides cases — an interface, a nullable — states none either.
 *
 * The files are the enums the read went through, whether or not it folded: adding a case or moving an arm
 * changes the answer.
 *
 * @phpstan-type FoldedStatus array{status: int|null, files: list<string>}
 *
 * @internal
 */
final class CaseStatus
{
    /** @var list<string> files the folder recorded during the current read */
    private array $recorded = [];

    private readonly EnumAccessorFolder $folder;

    public function __construct(FileAnalyzer $fileAnalyzer, ProjectFilter $appFilter)
    {
        $this->folder = new EnumAccessorFolder($fileAnalyzer, $appFilter, function (string $file): void {
            $this->recorded[] = $file;
        });
    }

    /**
     * The status `$expr` states in `$scope`. With no `$read`, `$expr` is the status itself — a constant, or a
     * no-argument method or `->value` read on a case. With one, `$expr` is the case and `$read` is how the
     * status comes off it, which is how a construction fills a slot its class reads through `->status()` or
     * `->value`.
     *
     * @return FoldedStatus
     */
    public function of(Node\Expr $expr, Scope $scope, ?ParamAccessor $read = null): array
    {
        if ($read === null) {
            $type = $scope->getType($expr);
            $call = self::caseRead($expr);
            if ($type instanceof ConstantIntegerType) {
                // PHPStan folded it already (`Case->value`, a constant), but a read on a case is still decided
                // in the enum.
                return ['status' => $type->getValue(), 'files' => $call === null ? [] : self::enumFiles($scope->getType($call[0]))];
            }
            if ($call === null) {
                return ['status' => null, 'files' => []];
            }

            [$expr, $read] = $call;
        }

        return $this->ofType($scope->getType($expr), $read);
    }

    /**
     * Whether `$expr` is a read on a choice of cases that read to different statuses: the case is chosen at
     * run time, so {@see of()} states no status for it, and nor may a union of the codes the read's own type
     * holds (`$fault->value` typed `410|423`). Inside a helper the choice is the parameter's type, and the
     * case a call passes is what decides it.
     */
    public function disagrees(Node\Expr $expr, Scope $scope): bool
    {
        $call = self::caseRead($expr);
        if ($call === null) {
            return false;
        }

        $type = $scope->getType($call[0]);
        if (! self::onlyCases($type)) {
            return false;
        }

        $statuses = [];
        foreach ($type->getEnumCases() as $case) {
            $folded = $this->folder->fold($case->getClassName(), $case->getEnumCaseName(), $call[1])?->value;
            if (! is_int($folded)) {
                return false;
            }
            $statuses[$folded] = true;
        }

        return count($statuses) > 1;
    }

    /**
     * The one status every case `$type` may be reads to through `$read`.
     *
     * @return FoldedStatus
     */
    private function ofType(Type $type, ParamAccessor $read): array
    {
        $cases = $type->getEnumCases();
        $files = self::enumFiles($type);

        if (! self::onlyCases($type)) {
            return ['status' => null, 'files' => $files];
        }

        $this->recorded = [];
        $status = null;
        foreach ($cases as $case) {
            $folded = $this->folder->fold($case->getClassName(), $case->getEnumCaseName(), $read)?->value;
            if (! is_int($folded) || ($status !== null && $folded !== $status)) {
                $status = null;
                break;
            }

            $status = $folded;
        }

        return ['status' => $status, 'files' => array_values(array_unique([...$files, ...$this->recorded]))];
    }

    /**
     * Whether the cases cover the type: only where it is nothing but those enums, since an interface or a
     * nullable beside them may be a value no case names.
     */
    private static function onlyCases(Type $type): bool
    {
        $enums = array_map(static fn (EnumCaseObjectType $case): ObjectType => new ObjectType($case->getClassName()), $type->getEnumCases());

        return $enums !== [] && TypeCombinator::union(...$enums)->isSuperTypeOf($type)->yes();
    }

    /**
     * The files declaring every enum `$type` may be a case of.
     *
     * @return list<string>
     */
    private static function enumFiles(Type $type): array
    {
        $files = [];
        foreach ($type->getEnumCases() as $case) {
            foreach (DeclarationFiles::of($case->getClassName()) as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * `$case->method()` with no arguments, or `$case->value`, as its receiver and the read it applies to it.
     *
     * @return array{Node\Expr, ParamAccessor}|null
     */
    private static function caseRead(Node\Expr $expr): ?array
    {
        if ($expr instanceof Node\Expr\PropertyFetch && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'value') {
            return [$expr->var, new ParamAccessor('', AccessorKind::Value)];
        }

        if (! $expr instanceof Node\Expr\MethodCall
            || ! $expr->name instanceof Node\Identifier
            || $expr->isFirstClassCallable()
            || $expr->getArgs() !== []
        ) {
            return null;
        }

        return [$expr->var, new ParamAccessor('', AccessorKind::Method, $expr->name->toString())];
    }
}
