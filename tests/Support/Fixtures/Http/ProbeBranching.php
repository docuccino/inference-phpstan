<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A constructor choosing its status by branch, which is not one status the class states. */
final class ProbeBranching extends HttpException
{
    public function __construct(bool $overdue)
    {
        if ($overdue) {
            parent::__construct(402, 'Payment required.');
        } else {
            parent::__construct(403, 'Blocked.');
        }
    }
}
