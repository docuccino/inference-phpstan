<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried;

use Illuminate\Http\Exceptions\HttpResponseException;

/** Builds the response it carries. */
final class ProbeLockedException extends HttpResponseException
{
    public function __construct()
    {
        parent::__construct(response()->json(['error' => 'locked'], 423));
    }
}
