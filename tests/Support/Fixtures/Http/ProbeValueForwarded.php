<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The status is the backing value of the case passed here. */
final class ProbeValueForwarded extends HttpException
{
    public function __construct(ProbeStatusValue $status)
    {
        parent::__construct($status->value, 'Unavailable.');
    }

    public static function locked(): self
    {
        return new self(ProbeStatusValue::Locked);
    }
}
