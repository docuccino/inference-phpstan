<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Provenance\MessagePaths;

/**
 * What one analysis could not read a status for, and the notices those records publish.
 *
 * It is the analyser's PUBLISHED half and nothing about a PHPStan scope drives it, so it lives here
 * rather than inside {@see ThrowAnalyzer}: which firings address somebody who can act, and the order
 * they go out in, are decisions a reader sees in the document.
 *
 * @internal
 */
final class UnreadStatuses
{
    /** @var array<string, UnreadStatus> by {@see UnreadStatus::key()} */
    private array $records = [];

    /**
     * Files one record and answers the missing status its caller hands back — the two are one
     * expression so that a status the document publishes as unplaced is one something recorded.
     */
    public function record(UnreadStatus $unread): null
    {
        $this->records[$unread->key()] = $unread;

        return null;
    }

    /**
     * One notice per unread throw — the exception, the site the `throw` is written at, and which fold
     * gave up ({@see UnreadStatus::sentence()}) — for the firings a reader can act on. Where it fires,
     * and the measurement that sized its population, are in docs/design/inference-embedding.md §6.
     *
     * Every unread status is recorded; this is where the ones whose remedy nobody owns are dropped, so
     * the actionability decision has one home and cannot be mistaken for the publish condition.
     *
     * The order is the records' own, never the order the analysis met them, and the sentence names an
     * analyser path — so it goes through `$labels` on the way out, the same crossing every other
     * message this engine composes makes.
     *
     * @return list<Diagnostic>
     */
    public function diagnostics(MessagePaths $labels): array
    {
        $keys = array_keys($this->records);
        sort($keys);

        $diagnostics = [];
        foreach ($keys as $key) {
            $unread = $this->records[$key];
            if (! $unread->isActionable()) {
                continue;
            }

            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'inference.http-exception-status-unread',
                message: $labels->relative($unread->sentence()),
                help: $unread->reason->remedy(),
            );
        }

        return $diagnostics;
    }
}
