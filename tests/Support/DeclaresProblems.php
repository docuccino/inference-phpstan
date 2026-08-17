<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Attributes\ErrorComponent;

/** The trait the attribute is really written in — the file a reader has to be sent to. */
trait DeclaresProblems
{
    #[ErrorComponent('TraitProblem')]
    public function traitDeclared(): void {}
}
