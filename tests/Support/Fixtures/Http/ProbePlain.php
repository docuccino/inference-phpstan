<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use RuntimeException;

/** Not an HttpException, so there is no status on it to read. */
final class ProbePlain extends RuntimeException {}
