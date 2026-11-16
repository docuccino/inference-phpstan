<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * A facade naming no accessor, whose root throws when asked for.
 */
final class ProbeUnboundFacade extends Facade {}
