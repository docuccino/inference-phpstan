<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** A subclass handing the base one case, which the base reads its status off. */
final class ProbeReasonSubclass extends ProbeReasonForwarded
{
    public function __construct()
    {
        parent::__construct(ProbeReason::Gone);
    }
}
