<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades;

use Illuminate\Support\Facades\Facade;
use ReflectionClass;

/**
 * A facade proxying a response factory, resolved without an application.
 */
final class ProbeFactoryFacade extends Facade
{
    public static function getFacadeRoot(): ProbeFactoryRoot
    {
        return (new ReflectionClass(ProbeFactoryRoot::class))->newInstanceWithoutConstructor();
    }
}
