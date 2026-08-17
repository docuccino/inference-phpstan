<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Trace;

use Closure;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\FoldsCallReturns;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * The engine-side {@see TypeScope} — the only type-engine surface a visitor sees. Wraps a PHPStan `Scope`
 * plus {@see TypeTranslator}: `PhpParser\Node` crosses the boundary, `PHPStan\*` stops here.
 *
 * @internal
 */
final class TypeScopeImpl implements FoldsCallReturns, TypeScope
{
    /**
     * The deferrer is the {@see Tracer}'s return-fold queue. It is absent where there is no
     * after-the-walk phase to answer in (a closure trace), which reads to a visitor as "this scope folds
     * no call returns".
     *
     * @param  (Closure(Node\Expr, callable(?ConstValue, ?Node\Expr): void): bool)|null  $deferrer
     */
    public function __construct(
        private readonly Scope $scope,
        private readonly TypeTranslator $translator,
        private readonly ?Closure $deferrer = null,
    ) {}

    public function typeOf(Node\Expr $expr): DType
    {
        return $this->translator->translate($this->scope->getType($expr));
    }

    /** Folded at the AST level, before PHPStan collapses a factory call — see {@see ConstantFolder}. */
    public function constantValueOf(Node\Expr $expr): ?ConstValue
    {
        return ConstantFolder::fold($expr, $this->scope);
    }

    public function deferReturnFold(Node\Expr $call, callable $onFolded): bool
    {
        return $this->deferrer !== null && ($this->deferrer)($call, $onFolded);
    }

    public function location(Node $node): SourceLocation
    {
        $pos = $node->getStartFilePos();

        return new SourceLocation(
            $this->writtenIn(),
            $node->getStartLine(),
            $pos < 0 ? null : $pos,
        );
    }

    /**
     * The file the node was WRITTEN in, which is not always the file being analysed: a trait body is
     * analysed once per using class, so its nodes carry the TRAIT's line numbers while the scope's file
     * names the class. Mixing the two names a line nobody wrote, and a visitor comparing that line against
     * a declaration's span then reads a trait's code as some unrelated method of the using class.
     */
    private function writtenIn(): string
    {
        return $this->scope->getTraitReflection()?->getFileName() ?? $this->scope->getFile();
    }
}
