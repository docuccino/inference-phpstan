<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Inference\PhpStan\Throwing\ClassBodies;
use PhpParser\Node;

/**
 * A {@see ClassBodies} that answers exactly like the one it wraps and remembers which calls it was asked to
 * fold an argument of — the observable for "this body was never read", which a return value alone cannot
 * show. A `parent::__construct()` is the class reading itself; a `new` is the factory hop.
 */
final class RecordingBodies implements ClassBodies
{
    /** @var list<'construction'|'parent-call'> what each fold was asked at, in order */
    public array $folded = [];

    public function __construct(private readonly ClassBodies $inner) {}

    public function methods(string $file, string $class): array
    {
        return $this->inner->methods($file, $class);
    }

    public function foldInt(string $file, Node\Expr $expr, Node\Expr\New_|Node\Expr\StaticCall $at): ?int
    {
        $this->folded[] = $at instanceof Node\Expr\New_ ? 'construction' : 'parent-call';

        return $this->inner->foldInt($file, $expr, $at);
    }

    public function intDefault(string $file, string $class, string $method, int $index): ?int
    {
        return $this->inner->intDefault($file, $class, $method, $index);
    }
}
