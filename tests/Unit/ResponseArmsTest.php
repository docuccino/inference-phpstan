<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\PayloadStatusT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\StatusTextMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Inference\PhpStan\Analysis\RefinedResponse;
use Docuccino\Inference\PhpStan\Analysis\ResponseArms;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * The several responses one return can be sent as. What an application's `toResponse()` answers is the
 * whole of what the server can send for it, so a response set is either every arm or nothing at all: a
 * subset published as the whole marks the missing arm as a response the endpoint never sends.
 */
function armChain(?int $status = null, ?string $contentType = null, bool $unknown = false, bool $statusUnknown = false): array
{
    return [
        'receiver' => new Node\Expr\Variable('response'),
        'status' => $status === null ? null : new LiteralT($status),
        'statusUnknown' => $statusUnknown,
        'contentType' => $contentType,
        'contentTypeUnknown' => $unknown,
    ];
}

it('keeps every arm or none of them', function (array $read, ?int $count): void {
    $whole = ResponseArms::whole($read);

    expect($whole === null ? null : count($whole))->toBe($count);
})->with(function (): array {
    $gone = new RefinedResponse(new ArrayShapeT([]), new LiteralT(410));
    $resource = RefinedResponse::renderedBy(new ClassT('App\\Http\\Resources\\WidgetResource'));

    return [
        'a guard arm beside the framework rendering' => [[$gone, $resource], 2],
        'one arm' => [[$resource], 1],
        'an arm nothing could read' => [[$gone, null], null],
        'an arm handing the response back to the framework' => [[$gone, RefinedResponse::delegation()], null],
        'no arms at all' => [[], null],
    ];
});

it('lays a chain over every arm, and loses them all when it leaves one saying nothing', function (): void {
    $gone = new RefinedResponse(new ArrayShapeT([]), new LiteralT(410));
    $resource = RefinedResponse::renderedBy(new ClassT('App\\Http\\Resources\\WidgetResource'));

    // `->setStatusCode(202)` after either arm is a 202: it is the last thing that ran.
    $stamped = ResponseArms::allLaid([$gone, $resource], armChain(202));
    expect(array_map(static fn (RefinedResponse $r): ?LiteralT => $r->status instanceof LiteralT ? $r->status : null, $stamped ?? []))
        ->toEqual([new LiteralT(202), new LiteralT(202)]);

    // A status stated and unread replaces the receiver's — the payload's own included — with nothing.
    $unread = ResponseArms::allLaid([$gone, $resource], armChain(statusUnknown: true));
    expect(array_map(static fn (RefinedResponse $r): bool => $r->statusUnread && $r->status === null && ! $r->statusOfPayload, $unread ?? []))
        ->toBe([true, true]);

    // A header over nothing the receiver recovered says nothing about the response.
    expect(ResponseArms::allLaid([new RefinedResponse], armChain()))->toBeNull()
        ->and(ResponseArms::laid(new RefinedResponse, armChain(contentType: 'application/problem+json'))?->contentType)->toBe('application/problem+json')
        // A header it cannot read clears the media type and leaves the payload's own status standing.
        ->and(ResponseArms::laid($resource->withContentType('text/plain'), armChain(unknown: true))?->toClassT('Illuminate\\Http\\JsonResponse')?->typeArgs[1])->toBeInstanceOf(PayloadStatusT::class);
});

it('gives a one-shape reader the arm only when there is exactly one', function (): void {
    $arm = new RefinedResponse(status: new LiteralT(202));

    expect(ResponseArms::single([$arm]))->toBe($arm)
        ->and(ResponseArms::single([$arm, $arm]))->toBeNull()
        ->and(ResponseArms::single(null))->toBeNull();
});

it('publishes each arm as its response, and an override it could not read as the bare class', function (): void {
    $json = 'Illuminate\\Http\\JsonResponse';
    $resource = new ClassT('App\\Http\\Resources\\WidgetResource');

    expect(ResponseArms::types([RefinedResponse::renderedBy($resource), new RefinedResponse(status: new LiteralT(410))], $json))
        ->toHaveCount(2)
        ->and(ResponseArms::types([RefinedResponse::renderedBy($resource)], $json)[0])->toEqual(new ClassT($json, [$resource, new PayloadStatusT]))
        ->and(ResponseArms::types(null, $json))->toEqual([new ClassT($json)])
        // A delegating arm has no response of its own; it is published as the class rather than dropped.
        ->and(ResponseArms::types([RefinedResponse::delegation()], $json))->toEqual([new ClassT($json)]);
});

it('reads parent::toResponse() as the rendered object only inside the override being read', function (string $code, ?string $scopeClass, ?string $overrideClass, bool $expected): void {
    $call = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';')[0]->expr;

    expect(ResponseArms::isParentRendering($call, $scopeClass, $overrideClass))->toBe($expected);
})->with([
    'parent::toResponse() in the override' => ['parent::toResponse($request)', 'App\\Guarded', 'App\\Guarded', true],
    'any casing' => ['PARENT::TORESPONSE($request)', 'App\\Guarded', 'App\\Guarded', true],
    'written in another class' => ['parent::toResponse($request)', 'App\\Helper', 'App\\Guarded', false],
    'no override being read' => ['parent::toResponse($request)', 'App\\Guarded', null, false],
    'parent::response(), which calls the override back' => ['parent::response($request)', 'App\\Guarded', 'App\\Guarded', false],
    'a named class' => ['JsonResource::toResponse($request)', 'App\\Guarded', 'App\\Guarded', false],
    'a dynamic name' => ['parent::{$method}($request)', 'App\\Guarded', 'App\\Guarded', false],
    'a dynamic class' => ['$class::toResponse($request)', 'App\\Guarded', 'App\\Guarded', false],
]);

it('publishes a return site once per arm, and as the resolved type when nothing was recovered', function (): void {
    $json = 'Illuminate\\Http\\JsonResponse';
    $resolved = new ClassT($json);
    $resource = new ClassT('App\\Http\\Resources\\WidgetResource');

    $sites = ResponseArms::sites([RefinedResponse::renderedBy($resource), new RefinedResponse(status: new LiteralT(410))], $resolved, $json);

    expect(array_column($sites, 'type'))->toEqual([
        new ClassT($json, [$resource, new PayloadStatusT]),
        new ClassT($json, [new UnknownT('payload not folded'), new LiteralT(410)]),
    ])
        ->and(ResponseArms::sites(null, $resolved, $json))->toEqual([['type' => $resolved, 'component' => null]])
        // A delegating arm is the framework's answer, no body of ours.
        ->and(ResponseArms::sites([RefinedResponse::delegation()], $resolved, $json)[0]['type'])->toBeInstanceOf(VoidT::class)
        // Nothing documentable in the arm keeps what the analyser resolved.
        ->and(ResponseArms::sites([new RefinedResponse], $resolved, $json)[0]['type'])->toBe($resolved);
});

it('takes the status echoes out of a body whose status a chain restates, and only then', function (array $chain, bool $echoes): void {
    // `new JsonResponse(['status' => $code, …], $code)->setStatusCode(500)` sends 500 beside a body that says
    // `$code`: the member no longer echoes the status it is sent with.
    $echoing = new RefinedResponse(new ArrayShapeT([
        new ArrayShapeField('status', new StatusMarkerT),
        new ArrayShapeField('title', new StatusTextMarkerT(ScalarT::string())),
    ]));

    $laid = ResponseArms::laid($echoing, $chain);
    $types = array_map(static fn (ArrayShapeField $field): string => $field->type->kind(), $laid?->payload instanceof ArrayShapeT ? $laid->payload->fields : []);

    expect($types)->toBe($echoes ? [StatusMarkerT::KIND, StatusTextMarkerT::KIND] : [ScalarT::KIND, ScalarT::KIND]);
})->with([
    'a status stated' => [armChain(500), false],
    'a status stated and unread' => [armChain(statusUnknown: true), false],
    'a header only' => [armChain(contentType: 'application/problem+json'), true],
]);
