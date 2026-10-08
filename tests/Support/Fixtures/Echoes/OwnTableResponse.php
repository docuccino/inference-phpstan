<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

use Symfony\Component\HttpFoundation\Response;

/** A response class that declares a status-text table of its own, which is not the one Symfony sends. */
final class OwnTableResponse extends Response
{
    /** @var array<int, string> */
    public static array $statusTexts = [404 => 'Missing'];
}
