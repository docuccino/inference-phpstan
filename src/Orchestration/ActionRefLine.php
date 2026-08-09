<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

use Docuccino\Core\Inference\ActionRef;

/**
 * An {@see ActionRef} paired with its pre-encoded NDJSON request line and stable
 * request id — the unit the {@see WorkerPool} queues, sends and tracks.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final readonly class ActionRefLine
{
    public string $id;

    public string $encoded;

    public function __construct(public ActionRef $ref)
    {
        $this->id = $ref->symbol();
        $this->encoded = WorkerProtocol::encodeLine(WorkerProtocol::action($ref));
    }
}
