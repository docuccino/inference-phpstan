<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

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

/**
 * A deterministic, PHPStan-free {@see TypeEngine} for the fast (non-fixture)
 * orchestration tests: each analysis echoes the action symbol so results can be
 * matched, and no Laravel/PHPStan boot is needed — letting the pool's scheduling,
 * containment, recycling and cache logic be exercised in milliseconds.
 */
final readonly class StubTypeEngine implements TypeEngine
{
    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        return new ActionAnalysis(
            returns: [new ReturnSite(
                new UnknownT('stub'),
                new SourceLocation($action->file, $action->line),
            )],
            throws: [],
            diagnostics: [new Diagnostic(
                Severity::Info,
                'stub.analysed',
                $action->symbol(),
            )],
            dependencyFiles: [$action->file],
        );
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        return new ActionAnalysis(
            returns: [new ReturnSite(new UnknownT('stub'), new SourceLocation($callable->file, $callable->line))],
            diagnostics: [new Diagnostic(Severity::Info, 'stub.analysed', $callable->symbol())],
            dependencyFiles: [$callable->file],
        );
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return new ClassMetadata($class->fqcn);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        // Nothing to walk.
        return new TraceReport;
    }
}
