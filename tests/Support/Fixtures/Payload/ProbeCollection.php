<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload;

/**
 * A typed collection whose element type nothing but a docblock generic can state — the shape spatie's
 * `DataCollection` has, and the reason a reflected class type has to stay open to being parameterised.
 * Only ever reflected.
 *
 * @template TKey of array-key
 * @template TValue
 */
final class ProbeCollection {}
