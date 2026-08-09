<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Orchestration\WorkerLoop;
use Docuccino\Inference\PhpStan\Orchestration\WorkerProtocol;
use Docuccino\Inference\PhpStan\Tests\Support\StubTypeEngine;

/**
 * @param  list<ActionRef>  $refs
 * @return list<array<string, mixed>>
 */
function runLoop(TypeEngine $engine, array $refs, int $maxActions = 50): array
{
    $in = fopen('php://temp', 'r+');
    $out = fopen('php://temp', 'r+');
    assert($in !== false && $out !== false);

    foreach ($refs as $ref) {
        fwrite($in, WorkerProtocol::encodeLine(WorkerProtocol::action($ref)));
    }
    rewind($in);

    (new WorkerLoop($engine, $maxActions, 1_073_741_824, $in, $out))->run();

    rewind($out);
    $raw = stream_get_contents($out);
    assert($raw !== false);

    $messages = [];
    foreach (explode("\n", $raw) as $line) {
        $message = WorkerProtocol::decodeLine($line);
        if ($message !== null) {
            $messages[] = $message;
        }
    }

    return $messages;
}

it('announces the booted engine, streams one result per action, then ends', function (): void {
    $refs = [
        new ActionRef('/app/A.php', 'App\\A', 'handle', 1),
        new ActionRef('/app/B.php', 'App\\B', 'handle', 2),
    ];

    $messages = runLoop(new StubTypeEngine, $refs);

    expect($messages[0]['t'])->toBe(WorkerProtocol::READY)
        ->and($messages[0]['engine'])->toBe(WorkerProtocol::ENGINE_PHPSTAN);

    $results = array_values(array_filter($messages, static fn (array $m): bool => $m['t'] === WorkerProtocol::RESULT));
    expect($results)->toHaveCount(2)
        ->and($results[0]['id'])->toBe('App\\A::handle')
        ->and($results[1]['id'])->toBe('App\\B::handle');
});

it('reports a null engine in the handshake when boot failed', function (): void {
    $messages = runLoop(new NullTypeEngine, [new ActionRef('/app/A.php', 'App\\A', 'handle', 1)]);

    expect($messages[0]['engine'])->toBe(WorkerProtocol::ENGINE_NULL);
});

it('self-recycles with a bye after the max-actions budget', function (): void {
    $refs = [
        new ActionRef('/app/A.php', 'App\\A', 'handle', 1),
        new ActionRef('/app/B.php', 'App\\B', 'handle', 2),
    ];

    $messages = runLoop(new StubTypeEngine, $refs, maxActions: 1);

    // One result then a recycle bye; the second action is never processed.
    $results = array_filter($messages, static fn (array $m): bool => $m['t'] === WorkerProtocol::RESULT);
    $byes = array_values(array_filter($messages, static fn (array $m): bool => $m['t'] === WorkerProtocol::BYE));
    expect($results)->toHaveCount(1)
        ->and($byes)->toHaveCount(1)
        ->and($byes[0]['reason'])->toBe('recycle');
});
