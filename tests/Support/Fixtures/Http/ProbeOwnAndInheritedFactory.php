<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/**
 * A subclass with a factory of its own under a base that also builds it. `new static(503)` in the base is a
 * construction of THIS class, so the two disagree and the class states no one status — reading only its own
 * declared code would publish the 413 for a response the base's factory builds as a 503.
 */
final class ProbeOwnAndInheritedFactory extends ProbeFactoryBase
{
    public static function tooLarge(): self
    {
        return new self(413, 'Too large.');
    }
}
