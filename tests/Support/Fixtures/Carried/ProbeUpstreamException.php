<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried;

/** Two levels below the framework, with no constructor of its own. */
final class ProbeUpstreamException extends ProbeRelayedException {}
