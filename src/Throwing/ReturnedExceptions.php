<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\ThrownException;

/**
 * The rule a {@see CallableRef::$returnsExceptions} analysis answers by, held apart from the read of any one
 * return so it can be stated without a scope: a return handing the parameter back translates nothing, one
 * that builds exceptions answers with them, and one that builds none this build can name comes back typed
 * {@see UnknownT} — so an incomplete answer never passes for a whole one.
 *
 * @internal
 */
final class ReturnedExceptions
{
    /**
     * @param  list<ReturnSite>  $returns  every reachable return, in the order harvested
     * @param  callable(int): list<ThrownException>  $read  the exceptions the return at that index builds
     * @return array{returns: list<ReturnSite>, throws: list<ThrownException>}
     */
    public static function of(array $returns, callable $read): array
    {
        $marked = [];
        $throws = [];

        foreach ($returns as $index => $site) {
            $built = $site->returnsParameter === null ? $read($index) : null;

            $marked[] = $built === []
                ? new ReturnSite(new UnknownT('names no exception class'), $site->location, $site->component, $site->returnsParameter, $site->conditions, $site->typeConditions)
                : $site;

            foreach ($built ?? [] as $throw) {
                $throws[] = $throw;
            }
        }

        return ['returns' => $marked, 'throws' => $throws];
    }
}
