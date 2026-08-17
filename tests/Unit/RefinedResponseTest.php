<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Analysis\AccessorKind;
use Docuccino\Inference\PhpStan\Analysis\ParamAccessor;
use Docuccino\Inference\PhpStan\Analysis\RefinedResponse;
use Docuccino\Inference\PhpStan\Analysis\ResponseShapeRefiner;

/** The value type of one member of a shape payload (helper for the binding assertions). */
function memberType(RefinedResponse $r, string $key): mixed
{
    expect($r->payload)->toBeInstanceOf(ArrayShapeT::class);
    foreach ($r->payload->fields as $field) {
        if ((string) $field->key === $key) {
            return $field->type;
        }
    }

    return null;
}

it('reports a delegation as non-documentable', function (): void {
    $delegation = RefinedResponse::delegation();

    expect($delegation->delegates)->toBeTrue()
        ->and($delegation->isDocumentable())->toBeFalse()
        ->and($delegation->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE))->toBeNull();
});

it('is documentable when any of payload, status, or content type is recovered', function (RefinedResponse $r, bool $documentable): void {
    expect($r->isDocumentable())->toBe($documentable);
})->with([
    'nothing' => [new RefinedResponse, false],
    'payload only' => [new RefinedResponse(payload: new ArrayShapeT([])), true],
    'status only' => [new RefinedResponse(status: new LiteralT(404)), true],
    'content type only' => [new RefinedResponse(contentType: 'application/problem+json'), true],
]);

it('emits a JsonResponse<payload, status, contentType> ClassT, content type arg only when set', function (): void {
    $type = (new RefinedResponse(new ArrayShapeT([]), new LiteralT(422), null, 'application/problem+json'))
        ->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE);

    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs)->toHaveCount(3)
        ->and($type->typeArgs[1])->toBeInstanceOf(LiteralT::class)
        ->and($type->typeArgs[2])->toEqual(new LiteralT('application/problem+json'));

    $noContentType = (new RefinedResponse(new ArrayShapeT([]), new LiteralT(200)))
        ->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE);
    expect($noContentType?->typeArgs)->toHaveCount(2);
});

it('places an UnknownT placeholder for an unfolded payload or status (honest, never guessed)', function (): void {
    $type = (new RefinedResponse(payload: null, status: null, contentType: 'application/problem+json'))
        ->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE);

    expect($type?->typeArgs[0])->toBeInstanceOf(UnknownT::class)
        ->and($type?->typeArgs[1])->toBeInstanceOf(UnknownT::class);
});

it('a ParamAccessor equals only its exact param+kind+method sibling', function (): void {
    $a = new ParamAccessor('problem', AccessorKind::Method, 'status');

    expect($a->equals(new ParamAccessor('problem', AccessorKind::Method, 'status')))->toBeTrue()
        ->and($a->equals(new ParamAccessor('problem', AccessorKind::Method, 'title')))->toBeFalse()
        ->and($a->equals(new ParamAccessor('problem', AccessorKind::Value)))->toBeFalse()
        ->and($a->equals(new ParamAccessor('other', AccessorKind::Method, 'status')))->toBeFalse()
        // Re-homing swaps only the parameter, preserving kind + method.
        ->and($a->withParam('outer'))->toEqual(new ParamAccessor('outer', AccessorKind::Method, 'status'))
        ->and(ParamAccessor::identity('detail'))->toEqual(new ParamAccessor('detail', AccessorKind::Identity));
});

it('binds a status source to a literal and clears the source', function (): void {
    $bound = (new RefinedResponse(status: null, statusSource: ParamAccessor::identity('status')))->withBoundStatus(new LiteralT(422));

    expect($bound->status)->toEqual(new LiteralT(422))
        ->and($bound->statusSource)->toBeNull();
});

it('re-homes a status source onto an outer parameter (transitive binding)', function (): void {
    $rehomed = (new RefinedResponse(status: new LiteralT(500), statusSource: new ParamAccessor('inner', AccessorKind::Method, 'status')))
        ->withStatusSource(new ParamAccessor('outer', AccessorKind::Method, 'status'));

    expect($rehomed->statusSource)->toEqual(new ParamAccessor('outer', AccessorKind::Method, 'status'))
        ->and($rehomed->status)->toBeNull(); // a pass-through is not simultaneously a literal
});

it('rewrites the payload and its member→accessor provenance while preserving everything else', function (): void {
    $original = new RefinedResponse(
        payload: new ArrayShapeT([]),
        status: new LiteralT(403),
        contentType: 'application/problem+json',
        payloadParamProvenance: ['status' => ParamAccessor::identity('status'), 'type' => ParamAccessor::identity('type')],
    );

    $bound = $original->withPayload(new ArrayShapeT([]), ['type' => ParamAccessor::identity('kind')]);

    expect($bound->payloadParamProvenance)->toEqual(['type' => ParamAccessor::identity('kind')])
        ->and($bound->status)->toEqual(new LiteralT(403))
        ->and($bound->contentType)->toBe('application/problem+json');

    // The status-only transforms carry the provenance through unchanged.
    expect($original->withBoundStatus(new LiteralT(404))->payloadParamProvenance)
        ->toEqual(['status' => ParamAccessor::identity('status'), 'type' => ParamAccessor::identity('type')]);
});

it('marks a status-source body member as a StatusMarkerT via the constructor factory', function (): void {
    $payload = new ArrayShapeT([
        new ArrayShapeField('type', ScalarT::string()),
        new ArrayShapeField('status', ScalarT::int()),
    ]);

    // Both the HTTP status and the `status` member read from the SAME accessor (`$problem->status()`) —
    // the member is marked; `type` reads a DIFFERENT accessor (`$problem->value`) and keeps its type.
    $statusSource = new ParamAccessor('problem', AccessorKind::Method, 'status');
    $refined = RefinedResponse::fromConstructor($payload, null, $statusSource, 'application/problem+json', [
        'type' => new ParamAccessor('problem', AccessorKind::Value),
        'status' => $statusSource,
    ]);

    expect(memberType($refined, 'status'))->toBeInstanceOf(StatusMarkerT::class)
        ->and(memberType($refined, 'type'))->toEqual(ScalarT::string())
        ->and($refined->statusSource)->toEqual($statusSource);

    // With a folded status (no source) nothing is marked.
    $literalStatus = RefinedResponse::fromConstructor($payload, new LiteralT(429), null, null, []);
    expect(memberType($literalStatus, 'status'))->toEqual(ScalarT::int());
});

it('binds a body member to a folded literal, dropping its provenance', function (): void {
    $refined = (new RefinedResponse(
        payload: new ArrayShapeT([new ArrayShapeField('type', ScalarT::string())]),
        payloadParamProvenance: ['type' => new ParamAccessor('problem', AccessorKind::Value)],
    ))->bindMember('type', new LiteralT('https://errors.test/conflict'), null);

    expect(memberType($refined, 'type'))->toEqual(new LiteralT('https://errors.test/conflict'))
        ->and($refined->payloadParamProvenance)->toBe([]);
});

it('re-homes a body member’s accessor onto an outer parameter when the argument is a caller parameter', function (): void {
    $refined = (new RefinedResponse(
        payload: new ArrayShapeT([new ArrayShapeField('title', ScalarT::string())]),
        payloadParamProvenance: ['title' => new ParamAccessor('inner', AccessorKind::Method, 'title')],
    ))->bindMember('title', null, new ParamAccessor('outer', AccessorKind::Method, 'title'));

    expect($refined->payloadParamProvenance)->toEqual(['title' => new ParamAccessor('outer', AccessorKind::Method, 'title')])
        ->and(memberType($refined, 'title'))->toEqual(ScalarT::string());
});

it('drops provenance and leaves a StatusMarkerT status member intact when the status does not fold', function (): void {
    $refined = (new RefinedResponse(
        payload: new ArrayShapeT([new ArrayShapeField('status', new StatusMarkerT)]),
        payloadParamProvenance: ['status' => new ParamAccessor('problem', AccessorKind::Method, 'status')],
    ))->bindMember('status', null, null);

    // Neither a literal nor a caller parameter: provenance drops, the marker survives for the seam to fill.
    expect(memberType($refined, 'status'))->toBeInstanceOf(StatusMarkerT::class)
        ->and($refined->payloadParamProvenance)->toBe([]);
});

it('is a payload no-op for a non-shape body (nothing to mark or bind)', function (): void {
    // fromConstructor must not mark a non-keyed-shape body even with a status source…
    $classPayload = new ClassT('App\\Data\\ErrorData');
    $statusSource = ParamAccessor::identity('status');
    $refined = RefinedResponse::fromConstructor($classPayload, null, $statusSource, null, ['status' => $statusSource]);
    expect($refined->payload)->toBe($classPayload)
        ->and($refined->statusSource)->toEqual($statusSource);

    // …and bindMember leaves a non-shape body untouched while still dropping the resolved provenance.
    $bound = (new RefinedResponse(payload: $classPayload, payloadParamProvenance: ['status' => $statusSource]))
        ->bindMember('status', new LiteralT(500), null);
    expect($bound->payload)->toBe($classPayload)
        ->and($bound->payloadParamProvenance)->toBe([]);
});

/** A response whose object payload was watched being constructed with `$members`, all still unfolded. */
function withMembers(array $members, array $provenance = []): RefinedResponse
{
    $fields = array_map(
        static fn (string $name): ArrayShapeField => new ArrayShapeField($name, new UnknownT('constructor argument not folded')),
        $members,
    );

    return (new RefinedResponse(payload: new ClassT('App\\Data\\ProblemData')))
        ->withPayloadMembers(new ArrayShapeT($fields), $provenance);
}

/** One constructor-argument member, or null when the member is gone. */
function supplied(RefinedResponse $r, string $key): ?ArrayShapeField
{
    foreach ($r->payloadMembers?->fields ?? [] as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
}

/** The value type of one constructor-argument member, or null when the member is gone. */
function suppliedType(RefinedResponse $r, string $key): mixed
{
    return supplied($r, $key)?->type;
}

it('pins an object payload’s member into the member map, leaving the class type alone', function (): void {
    // A ClassT has no fields to rewrite, so the folded value has to live beside it — and the payload must
    // stay the bare class identity the adapter converts to a schema.
    $payload = new ClassT('App\\Data\\ProblemData');
    $refined = (new RefinedResponse(payload: $payload))
        ->withPayloadMembers(
            new ArrayShapeT([new ArrayShapeField('title', new UnknownT('constructor argument not folded'))]),
            ['title' => new ParamAccessor('problem', AccessorKind::Method, 'title')],
        )
        ->bindMember('title', new LiteralT('Forbidden'), null);

    expect($refined->payload)->toBe($payload)
        ->and(suppliedType($refined, 'title'))->toEqual(new LiteralT('Forbidden'))
        ->and($refined->payloadParamProvenance)->toBe([]);
});

it('settles a conditional member when the call site renders it, and only that member', function (): void {
    // A callee writing `$errors ?? new Optional` can only say "sometimes"; a caller passing something that
    // cannot be null says "always, here". Nothing else about the map moves — including the other member the
    // callee left conditional, which this call site said nothing about.
    $refined = (new RefinedResponse(payload: new ClassT('App\\Data\\ProblemData')))
        ->withPayloadMembers(
            new ArrayShapeT([
                new ArrayShapeField('errors', new UnknownT('constructor argument not folded'), optional: true),
                new ArrayShapeField('instance', new UnknownT('constructor argument not folded'), optional: true),
            ]),
            ['errors' => ParamAccessor::identity('errors')],
        )
        ->bindMember('errors', null, null, rendersValue: true);

    expect(supplied($refined, 'errors')?->optional)->toBeFalse()
        ->and(supplied($refined, 'errors')?->type)->toBeInstanceOf(UnknownT::class)
        ->and(supplied($refined, 'instance')?->optional)->toBeTrue()
        ->and($refined->payloadParamProvenance)->toBe([]);
});

it('leaves a conditional member conditional when the argument could still be nothing', function (): void {
    // The caller's own value may be null, so the callee's fallback is still live: the member stays optional
    // and its provenance travels one hop out for whoever calls THIS.
    $refined = (new RefinedResponse(payload: new ClassT('App\\Data\\ProblemData')))
        ->withPayloadMembers(
            new ArrayShapeT([new ArrayShapeField('errors', new UnknownT('constructor argument not folded'), optional: true)]),
            ['errors' => ParamAccessor::identity('errors')],
        )
        ->bindMember('errors', null, ParamAccessor::identity('outer'));

    expect(supplied($refined, 'errors')?->optional)->toBeTrue()
        ->and($refined->payloadParamProvenance)->toEqual(['errors' => ParamAccessor::identity('outer')]);
});

it('pins a folded literal into a settled member, in one binding', function (): void {
    // A folded argument is a value by definition, so the member is both pinned and no longer conditional.
    $refined = (new RefinedResponse(payload: new ClassT('App\\Data\\ProblemData')))
        ->withPayloadMembers(
            new ArrayShapeT([new ArrayShapeField('instance', new UnknownT('constructor argument not folded'), optional: true)]),
            ['instance' => ParamAccessor::identity('instance')],
        )
        ->bindMember('instance', new LiteralT('/orders/1'), null, rendersValue: true);

    expect(supplied($refined, 'instance')?->type)->toEqual(new LiteralT('/orders/1'))
        ->and(supplied($refined, 'instance')?->optional)->toBeFalse();
});

it('drops a member the call site never supplied, and only that member', function (): void {
    // An unsupplied constructor argument took its default, so the member isn't in this response's body —
    // unlike an unbound array-shape member, which stays and merely widens.
    $refined = withMembers(['type', 'errors'], ['errors' => ParamAccessor::identity('errors')])
        ->withoutMember('errors');

    expect(suppliedType($refined, 'errors'))->toBeNull()
        ->and(suppliedType($refined, 'type'))->toBeInstanceOf(UnknownT::class)
        ->and($refined->payloadParamProvenance)->toBe([]);
});

it('leaves an array-shape payload’s members alone when there is no member map', function (): void {
    // withoutMember is only ever reached for an object payload; on anything else it may drop provenance but
    // must not touch the shape PHPStan gave us.
    $shape = new ArrayShapeT([new ArrayShapeField('errors', ScalarT::string())]);
    $refined = (new RefinedResponse(payload: $shape, payloadParamProvenance: ['errors' => ParamAccessor::identity('errors')]))
        ->withoutMember('errors');

    expect($refined->payload)->toBe($shape)
        ->and($refined->payloadMembers)->toBeNull()
        ->and($refined->payloadParamProvenance)->toBe([]);
});

it('emits the member map as a fourth arg, holding the content-type slot when there is none', function (): void {
    // The map has to stay fourth whether or not a media type was recovered, or the adapter would read it as
    // the content type. These args are read back by the pipeline, never by PHPStan.
    $withType = withMembers(['type'])->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE);
    expect($withType?->typeArgs)->toHaveCount(4)
        ->and($withType?->typeArgs[2])->toBeInstanceOf(UnknownT::class)
        ->and($withType?->typeArgs[3])->toBeInstanceOf(ArrayShapeT::class);

    $labelled = (new RefinedResponse(payload: new ClassT('App\\Data\\ProblemData'), contentType: 'application/problem+json'))
        ->withPayloadMembers(new ArrayShapeT([new ArrayShapeField('type', new LiteralT('about:blank'))]), [])
        ->toClassT(ResponseShapeRefiner::CANONICAL_RESPONSE);
    expect($labelled?->typeArgs)->toHaveCount(4)
        ->and($labelled?->typeArgs[2])->toEqual(new LiteralT('application/problem+json'))
        ->and($labelled?->typeArgs[3])->toBeInstanceOf(ArrayShapeT::class);
});

it('carries the member map through every other rewrite', function (): void {
    // Binding the status, re-homing it and re-labelling the media type all happen after discovery, so none
    // of them may lose the map on the way out.
    $base = withMembers(['type'], ['type' => ParamAccessor::identity('type')]);

    expect($base->withBoundStatus(new LiteralT(403))->payloadMembers)->not->toBeNull()
        ->and($base->withStatusSource(ParamAccessor::identity('status'))->payloadMembers)->not->toBeNull()
        ->and($base->withContentType('application/problem+json')->payloadMembers)->not->toBeNull()
        ->and($base->bindMember('type', null, ParamAccessor::identity('outer'))->payloadMembers)->not->toBeNull();
});

it('carries the declared component through every other rewrite', function (): void {
    // The name is stamped on a hop's way out and then bound, re-homed and re-labelled by every caller
    // above it. Losing it at any of those would publish the shared helper's name, or none at all.
    $declaration = new ComponentDeclaration('PortalRejection', 'App\\Exceptions\\Renderer::renderRejection');
    $base = withMembers(['type'], ['type' => ParamAccessor::identity('type')])->withComponent($declaration);

    expect($base->component)->toBe($declaration)
        ->and($base->withBoundStatus(new LiteralT(403))->component)->toBe($declaration)
        ->and($base->withStatusSource(ParamAccessor::identity('status'))->component)->toBe($declaration)
        ->and($base->withContentType('application/problem+json')->component)->toBe($declaration)
        ->and($base->bindMember('type', new LiteralT('x'), null)->component)->toBe($declaration)
        ->and($base->withoutMember('type')->component)->toBe($declaration)
        // The outermost hop replaces what came back from below it, rather than deferring to it.
        ->and($base->withComponent(new ComponentDeclaration('Outer', 'X::y'))->component?->name)->toBe('Outer')
        // Nothing declared anywhere leaves it unnamed, which is every render path before this existed.
        ->and((new RefinedResponse)->component)->toBeNull();
});

it('recognises the bare response class names it should try to enrich', function (): void {
    expect(ResponseShapeRefiner::isResponseFqcn('Illuminate\\Http\\JsonResponse'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('Illuminate\\Http\\Response'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('Symfony\\Component\\HttpFoundation\\Response'))->toBeTrue()
        ->and(ResponseShapeRefiner::isResponseFqcn('App\\Models\\User'))->toBeFalse();
});
