<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/**
 * A subclass whose base uses the trait. The class itself uses none, so an own-traits check sees nothing to
 * decline for — and the `new static(409)` that builds it is still in a file this read never opens.
 */
final class ProbeInheritsTraitFactory extends ProbeBaseWithTrait {}
