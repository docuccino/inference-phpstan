<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades;

/**
 * What {@see ProbeLedgerFacade} proxies: not a response factory.
 */
final class ProbeLedger
{
    /** @param  array<string, mixed>  $entry */
    public function json(array $entry, int $status = 200): string
    {
        return (string) json_encode([$entry, $status]);
    }
}
