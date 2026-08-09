<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Orchestration;

use Closure;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Throwable;

/**
 * Routes `analyzeAction` through a {@see WorkerPool}, so return/throw analysis runs across worker processes
 * with recycling, containment and the result cache, all behind the plain single-action contract.
 *
 * `trace()` and `classMetadata()` stay in-process: trace hands live `PhpParser\Node`s to a stateful visitor,
 * which can't cross a process boundary as NDJSON, and classMetadata is cheap enough that a worker
 * round-trip would cost more than the work. That in-process engine boots on first use, so an analyze-only
 * run never pays for a second container boot in the parent.
 *
 * @internal
 */
final class OrchestratedTypeEngine implements TypeEngine
{
    /** @var Closure(): TypeEngine */
    private readonly Closure $inProcessFactory;

    private ?TypeEngine $inProcess = null;

    /**
     * @param  callable(): TypeEngine  $inProcessFactory  builds the in-process engine for trace()/classMetadata()
     */
    public function __construct(
        private readonly WorkerPool $pool,
        callable $inProcessFactory,
    ) {
        $this->inProcessFactory = Closure::fromCallable($inProcessFactory);
    }

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        try {
            $results = $this->pool->analyze([$action]);

            return $results[$action->symbol()] ?? $this->failed($action, 'orchestrator returned no result');
        } catch (Throwable $e) {
            return $this->failed($action, $e->getMessage());
        }
    }

    /**
     * Fan many actions across the pool at once; {@see analyzeAction()} is a batch of one.
     *
     * @param  iterable<ActionRef>  $actions
     * @return array<string, ActionAnalysis> keyed by action id, sorted
     */
    public function analyzeActions(iterable $actions): array
    {
        return $this->pool->analyze($actions);
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        // In-process like trace()/classMetadata(): one handler analysis per build doesn't amortise a
        // worker round-trip, and it feeds the in-process pipeline directly.
        return $this->inProcess()->analyzeCallable($callable);
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->inProcess()->classMetadata($class);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        return $this->inProcess()->trace($action, $visitor);
    }

    private function inProcess(): TypeEngine
    {
        return $this->inProcess ??= ($this->inProcessFactory)();
    }

    private function failed(ActionRef $action, string $why): ActionAnalysis
    {
        return new ActionAnalysis(
            returns: [new ReturnSite(
                new UnknownT('orchestration failed: '.$why),
                new SourceLocation($action->file, $action->line),
            )],
            throws: [],
            diagnostics: [new Diagnostic(
                Severity::Error,
                'inference.orchestration-failed',
                sprintf('Orchestrated analysis of %s failed: %s', $action->symbol(), $why),
            )],
            dependencyFiles: [$action->file],
        );
    }
}
