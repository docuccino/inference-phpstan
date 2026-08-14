<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload;

/**
 * The element type a probe's `list<ProbeError>` docblock names. It lives in its own namespace so the
 * unqualified name in that docblock only resolves through the writing file's imports. Only ever reflected.
 */
final class ProbeError
{
    public function __construct(
        public readonly string $detail = '',
        public readonly string $pointer = '',
    ) {}
}
