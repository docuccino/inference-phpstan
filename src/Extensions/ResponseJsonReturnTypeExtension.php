<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Extensions;

use Docuccino\Inference\PhpStan\Support\ResponseFactoryCall;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
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
 * a dynamic one stays a plain int and falls back to the default. A call that spreads its arguments in from
 * somewhere unreadable ({@see ArgumentSlots}) is the same unknown: the framework's default is only true of
 * a call that provably passed no status of its own.
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
        return $methodReflection->getName() === ResponseFactoryCall::JSON || $methodReflection->getName() === ResponseFactoryCall::NO_CONTENT;
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $call = ResponseFactoryCall::arguments($methodCall);
        if ($call === null) {
            return null;
        }

        // No payload argument → fall back to the declared return type. An unreadable spread answers the
        // same, for a stronger reason: the payload is somewhere in there, and typing the spread expression
        // would document the ARGUMENT LIST as the response body. A void payload marks "no response body":
        // `noContent()` writes an empty body whatever status it carries.
        if ($call['method'] === ResponseFactoryCall::JSON && $call['body'] === null) {
            return null;
        }
        $payload = $call['body'] === null ? new VoidType : $scope->getType($call['body']);

        return new GenericObjectType(self::JSON_RESPONSE, [$payload, $this->status($call, $scope)]);
    }

    /**
     * The status argument's type: what the call site wrote, the framework's own default where it wrote
     * nothing, and a plain int where an unreadable spread means it may have written anything. The default
     * is only true of a call that provably passed no status — read out of a spread it would be a status
     * this endpoint never sends.
     *
     * @param  array{status: Expr|null, defaultStatus: int|null}  $call
     */
    private function status(array $call, Scope $scope): Type
    {
        if ($call['status'] !== null) {
            return $scope->getType($call['status']);
        }

        return $call['defaultStatus'] === null ? new IntegerType : new ConstantIntegerType($call['defaultStatus']);
    }
}
