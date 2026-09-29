<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** Every way a property can need no constructor reading, beside the one that does. */
class AssigningProbe
{
    public string $assigned;

    public string $defaulted = 'x';

    /** @var string */
    public $untyped;

    public function __construct(public string $promoted)
    {
        $this->assigned = $promoted;
    }
}
