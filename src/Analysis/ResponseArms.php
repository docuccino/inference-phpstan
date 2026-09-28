<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\VoidT;
use PhpParser\Node;

/**
 * The rules {@see ResponseShapeRefiner} applies to the several responses one return can be sent as — an
 * application-written `toResponse()` answering a guard arm beside the framework's rendering — held apart
 * from the scope-driven walk so they read (and test) without an analyser. The invariant: a subset of the
 * responses is never published as the whole, so one arm nothing could read loses them all.
 *
 * @phpstan-type Chain array{receiver: Node\Expr, status: LiteralT|UnionT|null, statusUnknown: bool, contentType: string|null, contentTypeUnknown: bool}
 *
 * @internal
 */
final class ResponseArms
{
    /**
     * A shape with a fluent chain's statements laid over it. A status stated on the wire beats whatever the
     * receiver carried — it is the last thing that ran. A receiver that recovers nothing still leaves a chain
     * worth reporting, but a shape saying nothing at all is not a response.
     *
     * @param  Chain  $chain
     */
    public static function laid(RefinedResponse $refined, array $chain): ?RefinedResponse
    {
        if ($chain['contentType'] !== null || $chain['contentTypeUnknown']) {
            $refined = $refined->withContentType($chain['contentType']);
        }
        if ($chain['status'] !== null) {
            $refined = $refined->withBoundStatus($chain['status']);
        } elseif ($chain['statusUnknown']) {
            // Stated and unread: it replaced whatever the receiver carried, so neither can stand.
            $refined = $refined->withUnreadStatus();
        }

        return $refined->isDocumentable() ? $refined : null;
    }

    /**
     * The chain over every arm, or null when it leaves any of them saying nothing.
     *
     * @param  non-empty-list<RefinedResponse>  $arms
     * @param  Chain  $chain
     * @return non-empty-list<RefinedResponse>|null
     */
    public static function allLaid(array $arms, array $chain): ?array
    {
        $laid = [];
        foreach ($arms as $arm) {
            $each = self::laid($arm, $chain);
            if ($each === null) {
                return null;
            }
            $laid[] = $each;
        }

        return $laid;
    }

    /**
     * The responses a method's returns were read as, or null unless EVERY one was read to a response: a
     * return nothing could read, or one handing the response to the framework, leaves the rest a subset.
     *
     * @param  list<RefinedResponse|null>  $read
     * @return non-empty-list<RefinedResponse>|null
     */
    public static function whole(array $read): ?array
    {
        $arms = [];
        foreach ($read as $arm) {
            if ($arm === null || $arm->delegates) {
                return null;
            }
            $arms[] = $arm;
        }

        return $arms === [] ? null : $arms;
    }

    /**
     * The one shape a caller that can carry only one takes: an override with several arms has none.
     *
     * @param  non-empty-list<RefinedResponse>|null  $arms
     */
    public static function single(?array $arms): ?RefinedResponse
    {
        return $arms !== null && count($arms) === 1 ? $arms[0] : null;
    }

    /**
     * The response type each arm is published as; an override that could not be read in full widens to
     * the bare response its method declares — vague, but a description of what is sent.
     *
     * @param  non-empty-list<RefinedResponse>|null  $arms
     * @return non-empty-list<ClassT>
     */
    public static function types(?array $arms, string $responseFqcn): array
    {
        $types = [];
        foreach ($arms ?? [] as $arm) {
            $types[] = $arm->toClassT($responseFqcn) ?? new ClassT($responseFqcn);
        }

        return $types === [] ? [new ClassT($responseFqcn)] : $types;
    }

    /**
     * What a harvested return site is published as, one entry per arm: a delegating arm is the framework's
     * answer (no body of ours), and nothing recovered keeps the type the analyser resolved.
     *
     * @param  list<RefinedResponse>|null  $arms
     * @return non-empty-list<array{type: DType, component: ComponentDeclaration|null}>
     */
    public static function sites(?array $arms, ClassT $resolved, string $responseFqcn): array
    {
        $sites = [];
        foreach ($arms ?? [] as $arm) {
            $sites[] = $arm->delegates
                ? ['type' => new VoidT, 'component' => null]
                : ['type' => $arm->toClassT($responseFqcn) ?? $resolved, 'component' => $arm->component];
        }

        return $sites === [] ? [['type' => $resolved, 'component' => null]] : $sites;
    }

    /**
     * Whether a static call is `parent::toResponse(…)` written in the class whose override is being read —
     * the framework (or a parent override) rendering the object that override renders. A class written
     * anywhere else calling its own parent is about its own object, and is not this.
     */
    public static function isParentRendering(Node\Expr\StaticCall $call, ?string $scopeClass, ?string $overrideClass): bool
    {
        return $overrideClass !== null
            && $scopeClass === $overrideClass
            && $call->class instanceof Node\Name
            && $call->class->toLowerString() === 'parent'
            && $call->name instanceof Node\Identifier
            && $call->name->toLowerString() === strtolower(ResponsableRendering::TO_RESPONSE);
    }
}
