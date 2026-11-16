<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * A facade proxying something other than a response factory, with methods named like a factory's.
 */
final class ProbeLedgerFacade extends Facade
{
    public static function getFacadeRoot(): ProbeLedger
    {
        return new ProbeLedger;
    }
}
