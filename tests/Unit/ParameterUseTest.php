<?php

declare(strict_types=1);

use Docuccino\Core\Inference\CallCondition;
use Docuccino\Inference\PhpStan\Analysis\ParameterUse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Whether `return $response;` hands a callback's parameter back as it arrived. Every row is a body an
 * application writes in a `respond()` callback. The return judged is the last one that hands back a
 * parameter, followed down through a returned ternary's or `match`'s branches.
 *
 * @return array{Node\Expr|null, list<string>, list<Node>}
 */
function parameterUseBody(string $code): array
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php $f = function ($response, $e) {'.$code.'};') ?? [];
    $closure = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Expr\Closure::class);
    assert($closure instanceof Node\Expr\Closure);

    $judged = null;
    foreach ((new NodeFinder)->findInstanceOf($closure->stmts, Node\Stmt\Return_::class) as $return) {
        $expr = $return->expr;
        while ($expr instanceof Node\Expr\Ternary || $expr instanceof Node\Expr\Match_) {
            $expr = $expr instanceof Node\Expr\Ternary ? $expr->else : $expr->arms[count($expr->arms) - 1]->body;
        }
        $judged = $expr instanceof Node\Expr\Variable || $judged === null ? $expr : $judged;
    }

    return [$judged, ['response', 'e'], array_values($closure->stmts)];
}

it('reads a returned parameter as unchanged only where nothing that can run first could change what it sends', function (string $code, ?string $echoed): void {
    [$expr, $parameters, $body] = parameterUseBody($code);

    expect(ParameterUse::echoed($expr, $parameters, $body))->toBe($echoed);
})->with([
    'returned as it arrived' => ['return $response;', 'response'],
    'read, then returned' => ['if ($response->getStatusCode() === 419) { return back(); } return $response;', 'response'],
    'tested for its class' => ['if ($response instanceof \\Illuminate\\Http\\JsonResponse) { return back(); } return $response;', 'response'],
    'a header other than the media type set' => ['$response->headers->set(\'X-Trace\', \'1\'); return $response;', 'response'],
    'a header removed' => ['$response->headers->remove(\'X-Powered-By\'); return $response;', 'response'],
    'a header set through the response' => ['$response->header(\'X-Trace\', \'1\'); return $response;', 'response'],
    'the media type rewritten through the header bag' => ['$response->headers->set(\'Content-Type\', \'text/plain\'); return $response;', null],
    'the media type rewritten in another case' => ['$response->headers->set(\'content-type\', \'text/plain\'); return $response;', null],
    'the media type removed' => ['$response->headers->remove(\'Content-Type\'); return $response;', null],
    'the media type rewritten through the response' => ['$response->header(\'Content-Type\', \'text/plain\'); return $response;', null],
    'a header named by a variable' => ['$response->headers->set($name, \'1\'); return $response;', null],
    'the header bag handed on' => ['$bag = $response->headers; $bag->set(\'Content-Type\', \'text/plain\'); return $response;', null],
    'the status rewritten' => ['$response->setStatusCode(500); return $response;', null],
    'the body rewritten' => ['$response->setContent(\'{}\'); return $response;', null],
    'reassigned' => ['$response = response()->json([]); return $response;', null],
    'written through' => ['$response->original = []; return $response;', null],
    'a method named at run time' => ['$response->{$method}(); return $response;', null],
    // An object is a handle: whatever it is handed to can write through it, and that code is not read here.
    'handed to a function' => ['report($response); return $response;', null],
    'handed to a static helper' => ['\\App\\Problem::decorate($response); return $response;', null],
    'handed to a method of another object' => ['$this->envelope($response); return $response;', null],
    'handed to a constructor' => ['new \\App\\Decorated($response); return $response;', null],
    'aliased, then written through the alias' => ['$alias = $response; $alias->setContent(\'{}\'); return $response;', null],
    'captured by a closure' => ['$fix = function () use ($response) { $response->setContent(\'{}\'); }; $fix(); return $response;', null],
    'every local overwritten by name' => ['extract($data); return $response;', null],
    // Order: what cannot run before the return does not change what it hands back.
    'rewritten only on a branch that returns something else' => ['if ($e) { $response->setContent(\'{}\'); return back(); } return $response;', 'response'],
    'rewritten on a branch that falls through to the return' => ['if ($e) { $response->setContent(\'{}\'); } return $response;', null],
    'rewritten on an elseif beside the return' => ['if ($e) { return $response; } elseif ($x) { $response->setContent(\'{}\'); } return back();', 'response'],
    'handed to a helper on the other branch of the returned ternary' => ['return $e ? \\App\\Problem::from($response) : $response;', 'response'],
    'handed to a helper in the returned ternary\'s condition' => ['return \\App\\Problem::applies($response) ? back() : $response;', null],
    'handed to a helper in another arm of the returned match' => ['return match (true) { $e instanceof \\RuntimeException => \\App\\Problem::from($response), default => $response };', 'response'],
    'rewritten after the return' => ['if ($e) { return $response; } $response->setContent(\'{}\'); return back();', 'response'],
    'rewritten after the return, in a loop around both' => ['foreach ($xs as $x) { if ($x) { return $response; } $response->setContent(\'{}\'); } return back();', null],
    'rewritten in a finally after the return' => ['try { return $response; } finally { $response->setContent(\'{}\'); }', null],
    // An exception the touch throws is caught, and the catch or what follows the try returns the parameter.
    'handed to a helper whose return is caught, the catch returning it' => ['try { return \\App\\Problem::from($response); } catch (\\Throwable) { return $response; }', null],
    'handed to a helper whose return is caught, the return after the try' => ['try { return \\App\\Problem::from($response); } catch (\\Throwable) {} return $response;', null],
    'handed to a helper before a throw that is caught' => ['try { \\App\\Problem::decorate($response); throw new \\RuntimeException; } catch (\\RuntimeException) {} return $response;', null],
    'handed to a helper in a try with only a finally' => ['try { return \\App\\Problem::from($response); } finally { report($e); } return $response;', 'response'],
    'handed to a helper whose throw leaves the try, the return inside it' => ['try { if ($e) { \\App\\Problem::decorate($response); throw new \\RuntimeException; } return $response; } catch (\\RuntimeException) { return back(); }', 'response'],
    'handed to a helper whose throw is caught in a loop around the return' => ['foreach ($xs as $x) { try { if ($x) { \\App\\Problem::decorate($response); throw new \\RuntimeException; } return $response; } catch (\\RuntimeException) {} } return back();', null],
    'handed to a helper in a catch that returns' => ['try { return back(); } catch (\\Throwable) { return \\App\\Problem::from($response); } return $response;', 'response'],
    // Escapes that never name the variable: the argument list, the scope, a name held as a string.
    'the arguments read back' => ['func_get_args()[0]->setContent(\'{}\'); return $response;', null],
    'one argument read back' => ['func_get_arg(0)->setContent(\'{}\'); return $response;', null],
    'the scope compacted by name' => ['\\App\\Problem::decorate(compact(\'response\')); return $response;', null],
    'the whole scope handed on' => ['\\App\\Problem::decorate(get_defined_vars()); return $response;', null],
    'named by a variable variable' => ['$name = \'response\'; $$name->setContent(\'{}\'); return $response;', null],
    'named by an expression' => ['${\'resp\'.\'onse\'}->setContent(\'{}\'); return $response;', null],
    'another parameter returned, named as itself' => ['return $e;', 'e'],
    'a local returned' => ['$other = 1; return $other;', null],
    'no expression returned' => ['return;', null],
]);

it('reads every header-bag method it lists as a reader as one that leaves the headers as they were', function (string $method, array $arguments): void {
    // Stated from the method itself: called on a real header bag, it changes nothing the response sends.
    $bag = new ResponseHeaderBag(['Content-Type' => 'application/json', 'X-Trace' => '1']);
    $before = $bag->all();
    $bag->{$method}(...$arguments);
    expect($bag->all())->toBe($before);

    $literal = implode(', ', array_map(static fn (string $a): string => var_export($a, true), $arguments));
    [$expr, $parameters, $body] = parameterUseBody('$response->headers->'.$method.'('.$literal.'); return $response;');

    expect(ParameterUse::echoed($expr, $parameters, $body))->toBe('response');
})->with(static function (): array {
    $arguments = ['get' => ['X-Trace'], 'has' => ['X-Trace'], 'contains' => ['X-Trace', '1']];
    $rows = [];
    foreach ((new ReflectionClassConstant(ParameterUse::class, 'HEADER_READERS'))->getValue() as $method) {
        $rows[$method] = [$method, $arguments[$method] ?? []];
    }

    return $rows;
});

it('reads a header-bag write it lists as leaving the media type alone only where it names another header', function (string $method): void {
    // Stated from the method itself: naming another header, the media type the response sends stands.
    $bag = new ResponseHeaderBag(['Content-Type' => 'application/json']);
    $method === 'set' ? $bag->set('X-Trace', '1') : $bag->remove('X-Trace');
    expect($bag->get('Content-Type'))->toBe('application/json');

    $other = $method === 'set' ? "'X-Trace', '1'" : "'X-Trace'";
    $media = $method === 'set' ? "'Content-Type', 'text/plain'" : "'Content-Type'";

    [$expr, $parameters, $body] = parameterUseBody('$response->headers->'.$method.'('.$other.'); return $response;');
    expect(ParameterUse::echoed($expr, $parameters, $body))->toBe('response');

    [$expr, $parameters, $body] = parameterUseBody('$response->headers->'.$method.'('.$media.'); return $response;');
    expect(ParameterUse::echoed($expr, $parameters, $body))->toBeNull();
})->with(static fn (): array => (new ReflectionClassConstant(ParameterUse::class, 'HEADER_WRITES'))->getValue());

it('reads a header-bag method it does not list as a change', function (string $call): void {
    [$expr, $parameters, $body] = parameterUseBody('$response->headers->'.$call.'; return $response;');

    expect(ParameterUse::echoed($expr, $parameters, $body))->toBeNull();
})->with([
    'add, which can set the media type' => ['add([\'Content-Type\' => \'application/problem+json\'])'],
    'replace, which can set the media type' => ['replace([\'Content-Type\' => \'application/problem+json\'])'],
    'a method it has never seen' => ['makeSomethingUp()'],
]);

it('reads a call on the response as a reader only by its name: get, is or has, then a capital', function (string $method, ?string $echoed): void {
    [$expr, $parameters, $body] = parameterUseBody('$response->'.$method.'(); return $response;');

    expect(ParameterUse::echoed($expr, $parameters, $body))->toBe($echoed);
})->with([
    'get…' => ['getContent', 'response'],
    'is…' => ['isSuccessful', 'response'],
    'has…' => ['hasVary', 'response'],
    'a prefix without the capital' => ['issue', null],
    'a writer' => ['setPrivate', null],
]);

it('reads a get/is/has method of a response as a reader only where calling it changes nothing the response sends', function (string $class, string $method): void {
    // Stated from the method itself: called on a response carrying validators the request matches, which is
    // the state in which a method named as a reader could still rewrite it.
    $response = match ($class) {
        JsonResponse::class => new JsonResponse(['message' => 'x'], 422),
        RedirectResponse::class => new RedirectResponse('https://example.test/'),
        default => new $class('{"message":"x"}', 422),
    };
    $response->setEtag('abc');
    $response->headers->set('Content-Type', 'application/problem+json');
    $request = Request::create('/', 'GET', server: ['HTTP_IF_NONE_MATCH' => '"abc"']);

    $sends = static fn (): array => [$response->getStatusCode(), $response->getContent(), $response->headers->allPreserveCase()];
    $before = $sends();
    $arguments = array_map(static fn (ReflectionParameter $p): mixed => match (true) {
        $p->isOptional() => $p->getDefaultValue(),
        (string) $p->getType() === Request::class => $request,
        default => 0,
    }, (new ReflectionMethod($class, $method))->getParameters());
    $response->{$method}(...$arguments);
    $changes = $sends() !== $before;

    [$expr, $parameters, $body] = parameterUseBody('$response->'.$method.'($request); return $response;');

    expect(ParameterUse::echoed($expr, $parameters, $body))->toBe($changes ? null : 'response');
})->with(static function (): array {
    $rows = [];
    foreach ([Symfony\Component\HttpFoundation\Response::class, Response::class, JsonResponse::class, RedirectResponse::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isStatic() && preg_match((new ReflectionClassConstant(ParameterUse::class, 'READER'))->getValue(), $method->getName()) === 1) {
                $rows[$class.'::'.$method->getName()] = [$class, $method->getName()];
            }
        }
    }

    // A reflection that stopped finding the readers would pass every row it no longer lists.
    expect(count($rows))->toBeGreaterThan(120);

    return $rows;
});

it('collects the literal-argument calls on a parameter, once each, in source order', function (): void {
    [, $parameters, $body] = parameterUseBody(<<<'PHP'
        if ($response->getStatusCode() === 419) { return back(); }
        if ($e->isFatal() || $response->getStatusCode() === 419) { return $response; }
        $local->is('api/*');
        $response->header($name);
        $response->header(...$names);
        $response->setStatusCode(500);
        return $response->headers->get('X');
    PHP);

    $calls = array_map(
        static fn (Node\Expr\MethodCall $call): string => $call->var instanceof Node\Expr\Variable && is_string($call->var->name) && $call->name instanceof Node\Identifier
            ? $call->var->name.'->'.$call->name->toString()
            : '?',
        ParameterUse::literalCalls($parameters, $body),
    );

    // Not a local's call, not one with a variable, spread or non-string argument, and not one reached
    // through a property: the receiver is a parameter and every argument is written where it is.
    expect($calls)->toBe(['response->getStatusCode', 'e->isFatal']);
});

it('states what a scope proves about each call as the one constant it answers', function (Type $type, bool|int|float|string|null $value): void {
    [, $parameters, $body] = parameterUseBody('if ($request->is(\'api/*\')) { return $response; } return $response;');
    $calls = ParameterUse::literalCalls([...$parameters, 'request'], $body);

    $scope = $this->createStub(Scope::class);
    $scope->method('getType')->willReturn($type);

    $conditions = ParameterUse::conditionsAt($scope, $calls);

    expect(array_map(static fn (CallCondition $c): array => $c->toArray(), $conditions))
        ->toBe($value === null ? [] : [['parameter' => 'request', 'method' => 'is', 'arguments' => ['api/*'], 'value' => $value]]);
})->with([
    'proven true' => [new ConstantBooleanType(true), true],
    'proven false' => [new ConstantBooleanType(false), false],
    'one status' => [new ConstantIntegerType(419), 419],
    'not proven' => [new BooleanType, null],
    'one of two' => [new UnionType([new ConstantIntegerType(419), new ConstantIntegerType(503)]), null],
    'a null, which states nothing' => [new NullType, null],
]);
