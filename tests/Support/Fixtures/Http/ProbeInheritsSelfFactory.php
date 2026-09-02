<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

/**
 * A subclass of the `self`-building base. `ProbeInheritsSelfFactory::gone()` hands back a
 * {@see ProbeSelfBuildingBase}, so the base's 410 is a status for another class's instance and this one
 * states none — the half of "read the hierarchy" that is a decline rather than an answer.
 */
final class ProbeInheritsSelfFactory extends ProbeSelfBuildingBase {}
