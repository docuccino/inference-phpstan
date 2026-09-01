<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/** The pin two classes up from `HttpException`, through a base that adds no constructor. */
final class ProbeInherited extends ProbeBase
{
    public function __construct()
    {
        parent::__construct(503, 'Offline.');
    }
}
