<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

use Docuccino\Core\Inference\ActionRef;

/**
 * An {@see ActionRef} with its pre-encoded NDJSON request line and stable id — what {@see WorkerPool}
 * queues, sends and tracks.
 *
 * @internal
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
