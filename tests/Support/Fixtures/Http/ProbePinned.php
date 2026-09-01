<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The status pinned as a literal reaching the parent — the commonest way an exception IS a status. */
final class ProbePinned extends HttpException
{
    public function __construct()
    {
        parent::__construct(422, 'Rejected.');
    }
}
