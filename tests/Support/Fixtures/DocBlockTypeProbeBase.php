<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeError;

/**
 * A parent whose own constructor carries the `@param` for its promoted property, so the factory has to look
 * the tag up on the DECLARING class rather than the one being reflected. Only ever reflected.
 */
class DocBlockTypeProbeBase
{
    /**
     * @param  list<ProbeError>  $inherited
     */
    public function __construct(public readonly array $inherited = []) {}
}
