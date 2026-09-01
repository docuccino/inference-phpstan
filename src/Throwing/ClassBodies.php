<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use PhpParser\Node;

/**
 * Where the HTTP-status read gets a class's method bodies from, who folds an expression written in one, and
 * what a parameter's default is. The hierarchy and the visibility are reflection, which needs nothing
 * analysed; only these three answers do, and behind them the read is ordinary code.
 *
 * @internal
 */
interface ClassBodies
{
    /**
     * Every method the class declares in `$file`, by method name → its statements. Empty where nothing
     * readable came back — an unparsable file, a class whose bodies were stripped.
     *
     * @return array<string, array<array-key, Node\Stmt>>
     */
    public function methods(string $file, string $class): array;

    /**
     * The constant integer `$expr` folds to where it is WRITTEN — in the scope at `$at`, the call it is an
     * argument of. A literal and a class constant alike; null where it is not one.
     *
     * The call is named rather than the method, because the scope at the end of a body answers a different
     * question: what a variable holds once the body has finished. A constructor that reassigns its status
     * parameter after forwarding it makes the two disagree, and the end scope's answer is a value the callee
     * never received.
     */
    public function foldInt(string $file, Node\Expr $expr, Node\Expr\New_|Node\Expr\StaticCall $at): ?int;

    /**
     * The constant integer default of parameter `$index` of `$class::$method`, as WRITTEN in `$file` — the
     * value a call leaving that slot empty passes.
     *
     * Read off the declaration and never by evaluating it: PHP has allowed `new` in an initialiser since
     * 8.1, and `ReflectionParameter::getDefaultValue()` runs it — so asking reflection for this would
     * execute the analysed application's own constructors inside the generator, and throw outright on a
     * default naming a constant that is not defined here.
     */
    public function intDefault(string $file, string $class, string $method, int $index): ?int;
}
