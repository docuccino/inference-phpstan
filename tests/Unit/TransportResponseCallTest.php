<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\ResponseFactoryCall;
use Docuccino\Inference\PhpStan\Support\TransportResponseCall;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeFactoryFacade;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Facades\ProbeLedgerFacade;
use Illuminate\Routing\Redirector;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Reflection\ReflectionProviderStaticAccessor;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Where a stream, file or redirect the framework makes takes its status: at the parameter the installed
 * signature names `status`, whichever spelling made it — the factory, the redirector, a helper, a facade or
 * `new` — so a call whose signature has no such parameter states none. The signatures are read off the
 * installed framework itself, as the analyser reads them.
 *
 * @return array{string, string|null, int|null}|null class, status argument, default status
 */
function transportResponseCallOf(string $code): ?array
{
    $call = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';')[0]->expr;

    $streamed = 'Symfony\\Component\\HttpFoundation\\StreamedResponse';
    $redirect = 'Illuminate\\Http\\RedirectResponse';
    // What each call hands back, as the analyser types it.
    $made = [
        'stream' => $streamed,
        'streamdownload' => $streamed,
        'streamjson' => 'Symfony\\Component\\HttpFoundation\\StreamedJsonResponse',
        'download' => 'Symfony\\Component\\HttpFoundation\\BinaryFileResponse',
        'json' => 'Illuminate\\Http\\JsonResponse',
        'to' => $redirect,
        'redirectto' => $redirect,
        'redirect' => $redirect,
        'back' => $redirect,
        'to_route' => $redirect,
        'app\\redirect' => $redirect,
    ];
    $nameOf = static fn (Node\Expr $expr): ?string => match (true) {
        $expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name => $expr->name->toLowerString(),
        ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) && $expr->name instanceof Node\Identifier => $expr->name->toLowerString(),
        default => null,
    };

    $scope = test()->createStub(Scope::class);
    $scope->method('resolveName')->willReturnCallback(static fn (Node\Name $name): string => $name->toString());
    $scope->method('getType')->willReturnCallback(static fn (Node\Expr $expr): Type => match (true) {
        // The helpers with no arguments hand back the factory and the redirector themselves.
        $expr instanceof Node\Expr\FuncCall && $expr->getRawArgs() === [] => new ObjectType($nameOf($expr) === 'redirect' ? Redirector::class : ResponseFactoryCall::CONTRACT),
        $expr instanceof Node\Expr\Variable && $expr->name === 'factory' => new ObjectType(ResponseFactoryCall::CONTRACT),
        $expr instanceof Node\Expr\Variable && $expr->name === 'other' => new ObjectType('App\\Exports'),
        $expr instanceof TypeExpr => $expr->getExprType() instanceof ObjectType && is_a($expr->getExprType()->getClassName(), ResponseFactoryCall::CONTRACT, true)
            ? new ObjectType(ResponseFactoryCall::CONTRACT)
            : $expr->getExprType(),
        isset($made[(string) $nameOf($expr)]) => new ObjectType($made[(string) $nameOf($expr)]),
        default => new MixedType,
    });

    // Parameters as the installed framework declares them: a name, and an integer default where there is one.
    $parameters = static function (ReflectionFunctionAbstract $native): array {
        return array_map(static function (ReflectionParameter $native): ExtendedParameterReflection {
            $default = $native->isDefaultValueAvailable() ? $native->getDefaultValue() : null;
            $parameter = test()->createStub(ExtendedParameterReflection::class);
            $parameter->method('getName')->willReturn($native->getName());
            $parameter->method('getDefaultValue')->willReturn(is_int($default) ? new ConstantIntegerType($default) : null);

            return $parameter;
        }, $native->getParameters());
    };
    $variants = static function (array $parameters): array {
        $variant = test()->createStub(ExtendedParametersAcceptor::class);
        $variant->method('getParameters')->willReturn($parameters);

        return [$variant];
    };

    $scope->method('getMethodReflection')->willReturnCallback(static function (Type $type, string $name) use ($parameters, $variants): ?ExtendedMethodReflection {
        $class = $type->getObjectClassNames()[0] ?? null;
        if ($class === null || ! method_exists($class, $name)) {
            return null;
        }
        $method = test()->createStub(ExtendedMethodReflection::class);
        $method->method('getVariants')->willReturn($variants($parameters(new ReflectionMethod($class, $name))));

        return $method;
    });

    // A namespaced `redirect()` an application declared itself is not the framework's helper.
    $reflection = test()->createStub(ReflectionProvider::class);
    $reflection->method('resolveFunctionName')->willReturnCallback(static fn (Node\Name $name): string => $name->toLowerString());
    $reflection->method('hasClass')->willReturnCallback(static fn (string $class): bool => class_exists($class));
    $reflection->method('getFunction')->willReturnCallback(static function (Node\Name $name) use ($parameters, $variants): FunctionReflection {
        $function = test()->createStub(FunctionReflection::class);
        $function->method('getVariants')->willReturn($variants($parameters(new ReflectionFunction($name->toString()))));

        return $function;
    });

    // Comparing object types asks PHPStan's static reflection, which only a booted analyser registers; one
    // reading no class leaves any two distinct classes unrelated.
    $accessor = new ReflectionProperty(ReflectionProviderStaticAccessor::class, 'instance');
    $previous = $accessor->getValue();
    ReflectionProviderStaticAccessor::registerInstance(test()->createStub(ReflectionProvider::class));
    try {
        $read = TransportResponseCall::of($call, $scope, $reflection);
    } finally {
        $accessor->setValue(null, $previous);
    }

    if ($read === null) {
        return null;
    }
    $status = $read['status'] === null ? null : $read['slots']->at($read['status']);

    return [$read['class'], $status === null ? null : (new Standard)->prettyPrintExpr($status), $read['default']];
}

it('reads the status wherever the framework makes a stream, file or redirect', function (string $code, array $expected): void {
    expect(transportResponseCallOf($code))->toBe($expected);
})->with([
    'stream() on response()' => ['response()->stream($cb, 202, $headers)', ['Symfony\\Component\\HttpFoundation\\StreamedResponse', '202', 200]],
    'streamJson(), status named' => ['response()->streamJson(status: 206, data: $rows)', ['Symfony\\Component\\HttpFoundation\\StreamedJsonResponse', '206', 200]],
    'stream() on an injected factory' => ['$factory->stream($cb, $code)', ['Symfony\\Component\\HttpFoundation\\StreamedResponse', '$code', 200]],
    'stream() through a facade' => ['\\'.ProbeFactoryFacade::class.'::stream($cb, 206)', ['Symfony\\Component\\HttpFoundation\\StreamedResponse', '206', 200]],
    'redirectTo() on response()' => ['response()->redirectTo(\'/archive\', 307)', ['Illuminate\\Http\\RedirectResponse', '307', 302]],
    'to() on the redirector' => ['redirect()->to(\'/archive\', 301)', ['Illuminate\\Http\\RedirectResponse', '301', 302]],
    'the redirect() helper' => ['redirect(\'/archive\', 301)', ['Illuminate\\Http\\RedirectResponse', '301', 302]],
    'the back() helper' => ['back(303)', ['Illuminate\\Http\\RedirectResponse', '303', 302]],
    'the to_route() helper' => ['to_route(\'archive\', [], 308)', ['Illuminate\\Http\\RedirectResponse', '308', 302]],
    'a stream built with new' => ['new \\Symfony\\Component\\HttpFoundation\\StreamedResponse($cb, 202)', ['Symfony\\Component\\HttpFoundation\\StreamedResponse', '202', 200]],
    'a file built with new' => ['new \\Symfony\\Component\\HttpFoundation\\BinaryFileResponse($file, 206)', ['Symfony\\Component\\HttpFoundation\\BinaryFileResponse', '206', 200]],
    'a redirect built with new' => ['new \\Illuminate\\Http\\RedirectResponse(\'/archive\', 301)', ['Illuminate\\Http\\RedirectResponse', '301', 302]],
    // Passed none: the slot is known to be empty, and the default is what is sent.
    'stream() passed no status' => ['response()->stream($cb)', ['Symfony\\Component\\HttpFoundation\\StreamedResponse', null, 200]],
]);

it('finds no status on a call whose signature takes none', function (string $code, string $class): void {
    // `download()` and `streamDownload()` hand their third argument to the headers, never to a status.
    expect(transportResponseCallOf($code))->toBe([$class, null, null]);
})->with([
    'download()' => ['response()->download($path, \'report.pdf\', $headers)', 'Symfony\\Component\\HttpFoundation\\BinaryFileResponse'],
    'streamDownload()' => ['response()->streamDownload($cb, \'ledger.csv\', $headers)', 'Symfony\\Component\\HttpFoundation\\StreamedResponse'],
]);

it('reads nothing off any other call', function (string $code): void {
    expect(transportResponseCallOf($code))->toBeNull();
})->with([
    // A JSON response is read by its own fold.
    'json() on response()' => ['response()->json($body, 422)'],
    'the helper alone' => ['redirect()'],
    'stream() on something else' => ['$other->stream($cb, 202)'],
    'an application\'s own redirect()' => ['App\\redirect(\'/archive\', 301)'],
    'a first-class callable' => ['response()->stream(...)'],
    'a dynamic method' => ['response()->{$method}($cb, 202)'],
    'a facade proxying something else' => ['\\'.ProbeLedgerFacade::class.'::stream($cb, 202)'],
    'an application\'s own stream class' => ['new \\App\\Http\\CsvResponse($cb, 202)'],
    'a dynamic class' => ['new $class($cb, 202)'],
]);
