<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VoidType;

/**
 * Keeps the payload shape and folded status of `response()->json([...], 201)` and
 * `response()->noContent()`, which otherwise infer as a bare `JsonResponse`/`Response` with both thrown
 * away. Re-attaches them as `JsonResponse<TPayload, TStatus>`, paired with the bundled `JsonResponse.stub`:
 * `json($payload, $status = 200)` → `JsonResponse<payloadShape, 200|literalStatus>`, and
 * `noContent($status = 204)` → `JsonResponse<void, …>` where the void payload means "no body".
 *
 * The status arg is the call-site type, so a constant int survives as a literal the pipeline can read while
 * a dynamic one stays a plain int and falls back to the default.
 *
 * Targets the ResponseFactory *contract*, not the concrete class — `response()` is typed as the contract at
 * the call site, and an extension aimed at the concrete class silently never fires. Laravel classes are
 * referenced by FQCN string because this package has no illuminate/* dependency; the extension only ever
 * runs inside a booted host app.
 *
 * @internal
 */
final class ResponseJsonReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const RESPONSE_FACTORY_CONTRACT = 'Illuminate\\Contracts\\Routing\\ResponseFactory';

    private const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    public function getClass(): string
    {
        // An FQCN string isn't provably class-string during analysis (illuminate/* isn't a dependency
        // here); it resolves at runtime inside the host app.
        /** @phpstan-ignore return.type */
        return self::RESPONSE_FACTORY_CONTRACT;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'json' || $methodReflection->getName() === 'noContent';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        if ($methodReflection->getName() === 'noContent') {
            return $this->noContent($methodCall, $scope);
        }

        $args = $methodCall->getArgs();

        // No payload argument → fall back to the declared return type.
        if (! isset($args[0])) {
            return null;
        }

        $payloadType = $scope->getType($args[0]->value);
        $statusType = isset($args[1]) ? $scope->getType($args[1]->value) : new ConstantIntegerType(200);

        return new GenericObjectType(self::JSON_RESPONSE, [$payloadType, $statusType]);
    }

    private function noContent(MethodCall $methodCall, Scope $scope): Type
    {
        $args = $methodCall->getArgs();
        $statusType = isset($args[0]) ? $scope->getType($args[0]->value) : new ConstantIntegerType(204);

        // A void payload marks "no response body"; the pipeline emits an empty response.
        return new GenericObjectType(self::JSON_RESPONSE, [new VoidType, $statusType]);
    }
}
