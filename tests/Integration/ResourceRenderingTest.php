<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\PayloadStatusT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A resource handed back as the response the framework renders from it. The router sends a returned
 * resource through its `toResponse()`, and `response()` is that same call on the current request, so
 * `->response()`, `->toResponse($request)` and the bare resource are one response: the resource's body,
 * under the status the resource decides — until a chain such as `->setStatusCode(201)` states one. An
 * application that writes `toResponse()` itself has written that response instead, and whichever way it
 * is reached, its own body and status are what goes out.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function renderedResourceAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/ResourceResponseController.php',
        'App\\Http\\Controllers\\ResourceResponseController',
        ['plain', 'response', 'created', 'toResponse', 'headed', 'store', 'named', 'collection', 'paginated', 'enveloped', 'overriddenResponse', 'overriddenToResponse', 'overriddenBare', 'guarded', 'guardedResponse', 'headeredOverride', 'acceptedOverride', 'inherited', 'relabelled', 'relaying'],
    );
}

function renderedResourceType(string $method): DType
{
    $types = renderedResourceTypes($method);
    expect($types)->toHaveCount(1);

    return $types[0];
}

/** @return list<DType> one per response the action's return sites can be sent as */
function renderedResourceTypes(string $method): array
{
    return array_map(
        static fn ($return): DType => $return->type,
        ActionAnalysis::fromArray(renderedResourceAnalyses()[$method])->returns,
    );
}

it('reads a framework-rendered resource as the resource, with no status of its own', function (string $method, string $payload): void {
    $type = renderedResourceType($method);

    // The payload, and the payload's own status, because the resource decides it the way it does for a
    // bare return. Any other status here — even an unknown one — would stop the created-model 201.
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs[1])->toBeInstanceOf(PayloadStatusT::class)
        ->and($type->typeArgs[0])->toBeInstanceOf(ClassT::class)
        ->and($type->typeArgs[0]->fqcn)->toBe($payload);
})->with([
    '->response()' => ['response', 'App\\Http\\Resources\\UserResource'],
    '->toResponse($request)' => ['toResponse', 'App\\Http\\Resources\\UserResource'],
    // A header is not a status: the resource still decides it.
    '->response()->header(…)' => ['headed', 'App\\Http\\Resources\\UserResource'],
    '->response() of a freshly created model' => ['store', 'App\\Http\\Resources\\UserResource'],
    // Named first, stamped, then returned: the local is followed back to what built it.
    'a local built by ::make()->response($request)' => ['named', 'App\\Http\\Resources\\UserResource'],
    '->additional(…)->response()' => ['enveloped', 'App\\Http\\Resources\\ReleaseResource'],
    // A media type stamped afterwards is not a status: the resource still decides it.
    '->response()->header(\'Content-Type\', …)' => ['relabelled', 'App\\Http\\Resources\\UserResource'],
    // An override handing the request back to the framework is the framework's rendering of THIS object.
    'an override returning parent::toResponse()->header(…)' => ['headeredOverride', 'App\\Http\\Resources\\HeaderedResource'],
])->group('fixture');

it('keeps the item resource of a rendered collection', function (string $method): void {
    $type = renderedResourceType($method);
    $collection = $type instanceof ClassT ? ($type->typeArgs[0] ?? null) : null;

    expect($collection)->toBeInstanceOf(ClassT::class)
        ->and($collection->fqcn)->toBe('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection')
        ->and($collection->typeArgs[0] ?? null)->toEqual(new ClassT('App\\Http\\Resources\\UserResource'))
        ->and($type->typeArgs[1] ?? null)->toBeInstanceOf(PayloadStatusT::class);
})->with(['collection', 'paginated'])->group('fixture');

it('lays a stated status over the rendered resource', function (): void {
    $type = renderedResourceType('created');

    expect($type)->toEqual(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ClassT('App\\Http\\Resources\\UserResource'),
        new LiteralT(201),
    ]));
})->group('fixture');

it('publishes an application-written toResponse() however the response is reached', function (string $method): void {
    $type = renderedResourceType($method);

    // The override's own `new JsonResponse(['queued' => true], 202)`, never the resource's `{id}` envelope —
    // including the bare return, which the router sends through that same override.
    expect($type)->toBeInstanceOf(ClassT::class)
        ->and($type->fqcn)->toBe('Illuminate\\Http\\JsonResponse')
        ->and($type->typeArgs[0])->toBeInstanceOf(ArrayShapeT::class)
        ->and((string) $type->typeArgs[0]->fields[0]->key)->toBe('queued')
        ->and($type->typeArgs[1])->toEqual(new LiteralT(202))
        ->and(array_map('basename', renderedResourceAnalyses()[$method]['dependencyFiles']))->toContain('SelfRespondingResource.php');
})->with(['overriddenResponse', 'overriddenToResponse', 'overriddenBare'])->group('fixture');

it('leaves a resource the framework renders as the resource when returned bare', function (): void {
    expect(renderedResourceType('plain'))->toEqual(new ClassT('App\\Http\\Resources\\UserResource'));
})->group('fixture');

it('keys a framework-rendered resource on the files an override could be added to', function (): void {
    // Adding a toResponse() to the resource — or to a base class it inherits from — changes the answer,
    // and none of those files is otherwise one this recovery read.
    $deps = array_map('basename', renderedResourceAnalyses()['enveloped']['dependencyFiles']);

    expect($deps)->toContain('ReleaseResource.php', 'EnvelopedResource.php')
        ->and($deps)->not->toContain('JsonResource.php');
})->group('fixture');

it('publishes every response an application-written toResponse() can send', function (string $method): void {
    // A guard arm beside `parent::toResponse()` is two responses. Publishing the first documentable one as
    // the whole would drop the resource envelope every other request gets.
    expect(renderedResourceTypes($method))->toEqual([
        new ClassT('Illuminate\\Http\\JsonResponse', [
            new ArrayShapeT([new ArrayShapeField('message', new LiteralT('This account is no longer available.'))]),
            new LiteralT(410),
        ]),
        new ClassT('Illuminate\\Http\\JsonResponse', [new ClassT('App\\Http\\Resources\\GuardedResource'), new PayloadStatusT]),
    ]);
})->with(['returned bare' => ['guarded'], 'through ->response()' => ['guardedResponse']])->group('fixture');

it('lays a status the override states over the framework rendering it hands back', function (): void {
    expect(renderedResourceType('acceptedOverride'))->toEqual(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ClassT('App\\Http\\Resources\\AcceptedResource'),
        new LiteralT(202),
    ]));
})->group('fixture');

it('publishes no subset of an override as the whole', function (): void {
    // One arm relays a body nothing can read. The resource arm alone would be a contract the relayed
    // response breaks, so the response widens to what the method declares and nothing more.
    expect(renderedResourceTypes('relaying'))->toEqual([new ClassT('Illuminate\\Http\\JsonResponse')]);
})->group('fixture');

it('keys an inherited override on the files a closer one could be added to', function (): void {
    // The class that wrote toResponse() is where the answer came from, and the resource that inherits it
    // is where a closer override would replace it — so a warm build is retired by editing either.
    $deps = array_map('basename', renderedResourceAnalyses()['inherited']['dependencyFiles']);

    expect(renderedResourceType('inherited'))->toEqual(new ClassT('Illuminate\\Http\\JsonResponse', [
        new ArrayShapeT([new ArrayShapeField('queued', new LiteralT(true))]),
        new LiteralT(202),
    ]))
        ->and($deps)->toContain('InheritingRespondingResource.php', 'SelfRespondingResource.php');
})->group('fixture');
