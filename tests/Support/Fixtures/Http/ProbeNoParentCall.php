<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A constructor that never reaches its parent, so nothing sets a status at all. */
final class ProbeNoParentCall extends HttpException
{
    public function __construct() {}
}
