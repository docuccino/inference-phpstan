<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Inference\PhpStan\Orchestration\WorkerProtocol;

it('round-trips an action reference through the wire format', function (): void {
    $ref = new ActionRef('/app/Foo.php', 'App\\Foo', 'bar', 12);

    $line = WorkerProtocol::encodeLine(WorkerProtocol::action($ref));
    $message = WorkerProtocol::decodeLine($line);

    expect($message)->not->toBeNull()
        ->and($message['t'])->toBe(WorkerProtocol::ACTION)
        ->and($message['id'])->toBe('App\\Foo::bar');

    $decoded = WorkerProtocol::actionRefFrom($message ?? []);
    expect($decoded)->not->toBeNull()
        ->and($decoded->file)->toBe('/app/Foo.php')
        ->and($decoded->class)->toBe('App\\Foo')
        ->and($decoded->method)->toBe('bar')
        ->and($decoded->line)->toBe(12);
});

it('preserves a null class for closure routes', function (): void {
    $ref = new ActionRef('/app/routes.php', null, 'closure', 3);
    $message = WorkerProtocol::decodeLine(WorkerProtocol::encodeLine(WorkerProtocol::action($ref)));

    $decoded = WorkerProtocol::actionRefFrom($message ?? []);
    expect($decoded?->class)->toBeNull();
});

it('ignores blank and non-object lines', function (): void {
    expect(WorkerProtocol::decodeLine(''))->toBeNull()
        ->and(WorkerProtocol::decodeLine('   '))->toBeNull()
        ->and(WorkerProtocol::decodeLine('42'))->toBeNull()
        ->and(WorkerProtocol::decodeLine('not json'))->toBeNull();
});

it('rejects an action message missing required fields', function (): void {
    expect(WorkerProtocol::actionRefFrom(['t' => 'a']))->toBeNull()
        ->and(WorkerProtocol::actionRefFrom(['file' => '/x.php']))->toBeNull();
});

it('builds ready, result and bye control messages', function (): void {
    expect(WorkerProtocol::ready(WorkerProtocol::ENGINE_NULL))
        ->toBe(['t' => 'ready', 'engine' => 'null']);
    expect(WorkerProtocol::result('App\\Foo::bar', ['returns' => []]))
        ->toBe(['t' => 'r', 'id' => 'App\\Foo::bar', 'analysis' => ['returns' => []]]);
    expect(WorkerProtocol::bye('recycle'))
        ->toBe(['t' => 'bye', 'reason' => 'recycle']);
});
