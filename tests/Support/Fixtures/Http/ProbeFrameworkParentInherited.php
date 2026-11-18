<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A framework fixed-status exception with no constructor of its own, so the framework's runs. */
final class ProbeFrameworkParentInherited extends ConflictHttpException {}
