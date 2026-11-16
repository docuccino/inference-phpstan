<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * A facade that resolves nothing, as one does before its binding is registered.
 */
final class ProbeRootlessFacade extends Facade
{
    public static function getFacadeRoot(): null
    {
        return null;
    }
}
