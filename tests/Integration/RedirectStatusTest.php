<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;

/**
 * A redirect is sent with the status its call names: `redirect()->to($url, 301)` answers 301, which is a
 * fact the bare `RedirectResponse` cannot state, so the document could only say "some 3xx". Every spelling
 * that takes a status reads it at the parameter the installed signature names `status`, as a stream's is
 * ({@see StreamStatusTest}).
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

/** @return array<string, array<string, mixed>> */
function redirectStatusAnalyses(): array
{
    static $analyses = null;

    return $analyses ??= FixtureRunner::analyzeMany(
        'app/Http/Controllers/RedirectStatusController.php',
        'App\\Http\\Controllers\\RedirectStatusController',
        ['helper', 'to', 'route', 'facade', 'back', 'toRoute', 'factory', 'constructed', 'helperStatus', 'plain', 'unread'],
    );
}

function redirectStatusReturn(string $method): DType
{
    $returns = ActionAnalysis::fromArray(redirectStatusAnalyses()[$method])->returns;
    expect($returns)->toHaveCount(1);

    return $returns[0]->type;
}

const REDIRECT_STATUS_CLASS = 'Illuminate\\Http\\RedirectResponse';

it('carries the status a redirect is sent with', function (string $method, int $status): void {
    expect(redirectStatusReturn($method))->toEqual(new ClassT(REDIRECT_STATUS_CLASS, [new UnknownT('payload not folded'), new LiteralT($status)]));
})->with([
    'redirect($url, 301)' => ['helper', 301],
    'redirect()->to($url, 301)' => ['to', 301],
    'redirect()->route($name, [], 308)' => ['route', 308],
    'Redirect::to($url, 301)' => ['facade', 301],
    'back(303)' => ['back', 303],
    'to_route($name, [], 308)' => ['toRoute', 308],
    'response()->redirectTo($url, 307)' => ['factory', 307],
    'new RedirectResponse($url, 301)' => ['constructed', 301],
    '$this->moved(308)' => ['helperStatus', 308],
])->group('fixture');

it('leaves a redirect sent with the framework\'s default as the bare class', function (): void {
    // `redirect($url)` names no status, so the class's own range is all the document can claim.
    expect(redirectStatusReturn('plain'))->toEqual(new ClassT(REDIRECT_STATUS_CLASS));
})->group('fixture');

it('claims no status where the one the call states cannot be read', function (): void {
    expect(redirectStatusReturn('unread')->typeArgs[1] ?? null)->toBeInstanceOf(UnknownT::class);
})->group('fixture');
