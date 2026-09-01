<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** No constructor at all: `HttpException`'s runs, and the status is the argument each `throw` writes. */
final class ProbeNoConstructor extends HttpException {}
