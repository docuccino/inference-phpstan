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
 * Preserves the payload shape AND the folded HTTP status of `response()->json([...], 201)`
 * and `response()->noContent()` (design §7, proven in Spike A). Out of the box the calls
 * infer as a bare `JsonResponse`/`Response` and both the constant array shape and the status
 * literal are discarded; this extension re-attaches them as `JsonResponse<TPayload, TStatus>`
 * (paired with the bundled `JsonResponse.stub`).
 *
 * - `json($payload, $status = 200)` → `JsonResponse<payloadShape, 200|literalStatus>`.
 * - `noContent($status = 204)` → `JsonResponse<void, 204|literalStatus>` (void payload = no body;
 *   the pipeline emits an empty response under the folded status).
 *
 * The status type arg is the call-site scope type of the status argument: a constant integer
 * survives as such (the pipeline reads the literal), a dynamic status stays a plain int (the
 * pipeline falls back to the phase default). Distinct return paths carry distinct statuses for
 * free — each return statement is harvested with its own recovered type.
 *
 * It targets the ResponseFactory *contract*, not the concrete class, because `response()` is
 * typed as the contract at the call site — target the concrete class and the extension silently
 * never fires (Spike A observation).
 *
 * Laravel classes are referenced by FQCN string rather than imported: this package carries no
 * hard dependency on illuminate/*, and the extension only ever executes inside a booted host app
 * where those classes exist.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class ResponseJsonReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    private const RESPONSE_FACTORY_CONTRACT = 'Illuminate\\Contracts\\Routing\\ResponseFactory';

    private const JSON_RESPONSE = 'Illuminate\\Http\\JsonResponse';

    public function getClass(): string
    {
        // The contract is referenced by FQCN string (illuminate/* is not a root
        // dependency — see the package's static-analysis note), so the literal is
        // not provably class-string during analysis. It resolves at runtime inside
        // the host app, where the class exists.
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
