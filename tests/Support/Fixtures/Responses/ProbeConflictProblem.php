<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

/** One kind of problem: its own body and status, under the media type its parent fixes. */
final class ProbeConflictProblem extends ProbeProblemResponse
{
    public function __construct()
    {
        parent::__construct(['title' => 'Conflict'], 409);
    }
}
