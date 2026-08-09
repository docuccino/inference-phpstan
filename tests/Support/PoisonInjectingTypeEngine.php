<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;

/**
 * Test-only decorator that simulates a "poison" action: when asked to analyse the
 * action named in the `DOCUCCINO_POISON_SYMBOL` env var it hard-exits the worker
 * process (an uncatchable fatal), exercising the pool's crash-containment and
 * bisection path. Every other action delegates to the real engine, so sibling
 * actions must still succeed.
 */
final readonly class PoisonInjectingTypeEngine implements TypeEngine
{
    public function __construct(private TypeEngine $inner) {}

    public function analyzeAction(ActionRef $action): ActionAnalysis
    {
        $poison = getenv('DOCUCCINO_POISON_SYMBOL');
        if (is_string($poison) && $poison !== '' && $action->symbol() === $poison) {
            fwrite(STDERR, "poison: crashing on {$poison}\n");
            exit(70);
        }

        return $this->inner->analyzeAction($action);
    }

    public function analyzeCallable(CallableRef $callable): ActionAnalysis
    {
        return $this->inner->analyzeCallable($callable);
    }

    public function classMetadata(ClassRef $class): ClassMetadata
    {
        return $this->inner->classMetadata($class);
    }

    public function trace(ActionRef $action, TraceVisitor $visitor): TraceReport
    {
        return $this->inner->trace($action, $visitor);
    }
}
