<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/** Hands its parent a response of its own making, so nothing the parent reads is an argument of this one. */
final class ConstantParentEchoes extends EchoingParent
{
    public readonly int $code;

    public function __construct(Response $rendered)
    {
        parent::__construct(new Response('', 418));

        $this->code = $this->status;
    }
}
