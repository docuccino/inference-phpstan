<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\Response;

/** A literal reaching the parent: the class IS a 410. */
final class ProbeGoneResponse extends Response
{
    public function __construct()
    {
        parent::__construct('', 410);
    }
}
