<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

/** A class whose constants are declared across three files, so `self::` and `parent::` name two of them. */
final class ProbeConstantHolder extends ProbeConstantBase
{
    public const OWN = 409;

    /** The base's value overridden here, so the two relative names name two different files. */
    public const SHADOWED = 409;

    /** No HTTP status at all — what a class reaching for the wrong constant hands its parent. */
    public const OUT_OF_RANGE = 99;
}
