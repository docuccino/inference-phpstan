<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A base that adds nothing, so its subclasses still meet `HttpException`'s own constructor. */
abstract class ProbeBase extends HttpException {}
