<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Responses;

use Illuminate\Http\JsonResponse;

/** Adds no constructor, so the framework's runs and its slots are the class's own. */
final class ProbePlainJsonResponse extends JsonResponse {}
