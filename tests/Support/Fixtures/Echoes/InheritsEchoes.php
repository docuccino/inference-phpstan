<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Echoes;

/** No constructor of its own: the one it inherits is the one that runs. */
final class InheritsEchoes extends EchoingBase {}
