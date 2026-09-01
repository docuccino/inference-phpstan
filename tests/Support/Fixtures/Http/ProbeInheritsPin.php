<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** A subclass of a base that pins: it can only choose the message. */
final class ProbeInheritsPin extends ProbePinningBase
{
    public function __construct()
    {
        parent::__construct('Long gone.');
    }
}
