<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** Only its named constructor builds it. */
final class PrivateProbe
{
    public string $title;

    private function __construct() {}

    public static function make(): self
    {
        $probe = new self;
        $probe->title = 'x';

        return $probe;
    }
}
