<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload;

/**
 * A "this member may be absent" marker, the shape spatie/laravel-data's `Optional` has — the other arm of a
 * promoted `array|Optional` property. Only ever reflected.
 */
final class ProbeOptional {}
