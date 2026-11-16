<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Closure;
use Docuccino\Core\Inference\ComponentDeclaration;
use PhpParser\Node;

/**
 * A narrowed renderer's site that hands the exception on to a project helper, and the site standing for it
 * once the helper has chosen its own for the same exception ({@see PhpStanTypeEngine::harvestNarrowed()}).
 *
 * @phpstan-import-type NarrowedSite from PhpStanTypeEngine
 *
 * @internal
 */
final class ForwardedSite
{
    /**
     * The call a site hands the exception to: the site itself, or the object a `->toResponse()` renders where
     * `$rendersOwn` says the application wrote that method, since sending the object is the same response as
     * returning it. Null for any other expression, and for a first-class callable, which calls nothing.
     *
     * @param  Closure(Node\Expr): bool  $rendersOwn  whether an object's own `toResponse()` is the application's
     */
    public static function call(Node\Expr $expr, Closure $rendersOwn): Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\FuncCall|null
    {
        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->toLowerString() === strtolower(ResponsableRendering::TO_RESPONSE)
            && $rendersOwn($expr->var)
        ) {
            $expr = $expr->var;
        }

        $isCall = $expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall || $expr instanceof Node\Expr\FuncCall;

        return $isCall && ! $expr->isFirstClassCallable() ? $expr : null;
    }

    /**
     * The site standing for one that handed the exception on: its own position and guard, which decide
     * whether it is reached at all, and the helper's answer — what it builds, and the expression and scope
     * that is read in. The name the helper declares outranks any below it, as a descended hop's does; a
     * delegation answers with no body for a name to be about.
     *
     * @param  NarrowedSite  $site
     * @param  NarrowedSite  $chosen  the helper's site for the same exception
     * @return NarrowedSite
     */
    public static function answeredBy(array $site, array $chosen, ?ComponentDeclaration $declared): array
    {
        $site['type'] = $chosen['type'];
        $site['component'] = $chosen['delegates'] ? $chosen['component'] : ($declared ?? $chosen['component']);
        $site['delegates'] = $chosen['delegates'];
        $site['expr'] = $chosen['expr'];
        $site['foldScope'] = $chosen['foldScope'];

        return $site;
    }
}
