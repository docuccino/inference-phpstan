<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Inference\PhpStan\Support\ContentTypeHeader;
use Docuccino\Inference\PhpStan\Support\ScalarFold;
use Docuccino\Inference\PhpStan\Translation\TypeTranslator;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\StaticType;

/**
 * Peels the fluent tail off a response expression — `->setStatusCode(202)`, `->header('X-Total', $n)`,
 * `->withHeaders([...])` — so {@see ResponseShapeRefiner} can refine the response where it was BUILT and
 * then apply what the chain restated. Without this a status the application states plainly is lost, or
 * worse inherited from the receiver: `response()->json($body)` types as `JsonResponse<…, 200>` and
 * `setStatusCode()` returns `static`, so the 200 survives a `->setStatusCode(201)` unchallenged.
 *
 * The grammar has exactly one rule, and the guard is the same function as the fold ({@see readLink()}), so
 * it can never unwrap a shape it cannot vouch for: a call whose RECEIVER is one of the response classes is
 * a MUTATOR of that response, and it is peeled only when it is one of the three links below and the
 * INSTALLED framework declares it as one that hands the same object back ({@see linkParameters()}). A
 * mutator failing either test — `->setData($other)`, a status this build cannot fold — declines the WHOLE
 * chain rather than passing the receiver's shape through something that may have rewritten it. A call whose
 * receiver is not a response is a PRODUCER: that is the base, and the refiner takes it from there.
 *
 * `withStatus()` is deliberately absent: it is PSR-7's setter, and no class in
 * {@see ResponseShapeRefiner::isResponseFqcn()} declares it, so reading it would be a name that can never
 * fire. Subclasses are absent for a sharper reason — a strict receiver check is what keeps a
 * `(new StreamedResponse(…))->setStatusCode(202)` out of here, since the refiner emits everything it
 * recovers as a `JsonResponse` and a streamed body is not one.
 *
 * A header link is the one place the two facts part company: a `->header($name, $v)` this cannot read
 * cannot have touched the STATUS, so refusing the whole chain over it would leave the receiver's status
 * standing where the chain plainly restated it. Such a link reports its media type as UNKNOWN instead,
 * which drops whatever the receiver carried rather than publishing a type the header may have replaced.
 *
 * @phpstan-type PeeledChain array{receiver: Node\Expr, status: LiteralT|null, contentType: string|null, contentTypeUnknown: bool}
 * @phpstan-type ChainLink array{status: LiteralT|null, contentType: string|null, contentTypeUnknown: bool}
 *
 * @internal
 */
final class FluentResponseChain
{
    /** Sets the response's own status. */
    private const STATUS = 'setStatusCode';

    /** Sets ONE header by name and value. */
    private const HEADER = 'header';

    /** Sets a map of headers at once. */
    private const HEADERS = 'withHeaders';

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly TypeTranslator $translator,
    ) {}

    /**
     * The receiver the chain was built on plus what its links stated, or null when there is no chain here
     * — either nothing was peeled, or a mutator this cannot read made the whole tail unsafe.
     *
     * Merging is outermost-first because the outermost call is the one that ran LAST: `->setStatusCode(201)
     * ->setStatusCode(202)` sends a 202, and a replacing `Content-Type` header works the same way.
     *
     * @return PeeledChain|null
     */
    public function peel(Node\Expr $expr, Scope $scope): ?array
    {
        $status = null;
        $contentType = null;
        $unknown = false;
        $current = $expr;
        $peeled = false;

        while ($current instanceof Node\Expr\MethodCall) {
            $link = $this->readLink($current, $scope);
            if ($link === null) {
                break; // a producer, not a mutator — the shape is this expression's
            }
            if ($link === false) {
                return null; // a mutator this cannot read: the chain declines rather than guessing
            }

            $status ??= $link['status'];
            // Whichever of the two a link settles, it settles for the chain: an inner header cannot
            // undo an outer one, and neither can it undo an outer one this could not read.
            if ($contentType === null && ! $unknown) {
                $contentType = $link['contentType'];
                $unknown = $link['contentTypeUnknown'];
            }
            $current = $current->var;
            $peeled = true;
        }

        return $peeled
            ? ['receiver' => $current, 'status' => $status, 'contentType' => $contentType, 'contentTypeUnknown' => $unknown]
            : null;
    }

    /**
     * One link of a chain, in three answers: `null` = the call is not a mutation of a response at all (its
     * receiver is something else), so it is the base; `false` = it mutates a response in a way this cannot
     * account for, so the chain is off; an array = what this link stated, `[null, null]` for one that
     * touched neither the body nor the status.
     *
     * @return ChainLink|false|null
     */
    private function readLink(Node\Expr\MethodCall $call, Scope $scope): array|false|null
    {
        $receiver = $this->translator->translate($scope->getType($call->var));
        if (! $receiver instanceof ClassT || ! ResponseShapeRefiner::isResponseFqcn($receiver->fqcn)) {
            return null;
        }

        if (! $call->name instanceof Node\Identifier) {
            return false; // `$response->{$method}()` — a mutation this cannot even name
        }

        $name = $call->name->toString();
        if ($name !== self::STATUS && $name !== self::HEADER && $name !== self::HEADERS) {
            return false;
        }

        $params = $this->linkParameters($receiver->fqcn, $name);
        if ($params === null || $call->isFirstClassCallable()) {
            return false;
        }

        $args = ArgumentSlots::of($call->getArgs(), $params);

        return match ($name) {
            self::STATUS => $this->readStatus($args, $scope),
            self::HEADER => $this->readHeader($args, $scope),
            default => $this->readHeaders($args, $scope),
        };
    }

    /**
     * The parameter names of a link as the INSTALLED framework declares it, or null when what it declares
     * is not a link at all: no such method, or one that does not hand back the object it was called on
     * (`static`, or a `@return $this` — which is how the framework's own trait spells the header setters).
     * A vendor package's fluent grammar is a function of the version the app resolved, so this reads that
     * version's own reflection rather than the one it was written against; a release that moved either
     * fact degrades here instead of publishing this major's dialect.
     *
     * @return list<string>|null
     */
    private function linkParameters(string $fqcn, string $method): ?array
    {
        if (! $this->reflectionProvider->hasClass($fqcn)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($fqcn);
        if (! $class->hasNativeMethod($method)) {
            return null;
        }

        $variant = $class->getNativeMethod($method)->getVariants()[0];
        if (! $variant->getReturnType() instanceof StaticType) {
            return null;
        }

        $names = [];
        foreach ($variant->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }

    /**
     * `setStatusCode(202)`. A status this build cannot fold to an int is the one thing worth refusing the
     * whole chain over: the code plainly states a status, so passing the receiver's through would publish
     * one the endpoint may never send.
     *
     * @return ChainLink|false
     */
    private function readStatus(ArgumentSlots $args, Scope $scope): array|false
    {
        $code = $args->at(0);
        if ($code === null) {
            return false; // absent or opaque — either way, unreadable
        }

        $folded = ScalarFold::of($scope->getType($code));
        if ($folded === null || ! is_int($folded[0])) {
            return false;
        }

        return ['status' => new LiteralT($folded[0]), 'contentType' => null, 'contentTypeUnknown' => false];
    }

    /**
     * `header('Content-Type', 'application/problem+json')` states the media type; `header('X-Total', $n)`
     * states nothing this documents. Anything in between — a name that will not fold, a value that will
     * not, a `$replace` that is not provably true (appending a second `Content-Type` is not the same claim
     * as setting one) — leaves the media type unknown, which is the whole of what a header can affect.
     *
     * @return ChainLink
     */
    private function readHeader(ArgumentSlots $args, Scope $scope): array
    {
        $name = $args->at(0);
        $folded = $name === null ? null : ScalarFold::of($scope->getType($name));
        if ($folded === null || ! is_string($folded[0])) {
            return self::unknownMediaType();
        }
        if (! ContentTypeHeader::names($folded[0])) {
            return ['status' => null, 'contentType' => null, 'contentTypeUnknown' => false];
        }

        $replace = $args->at(2);
        $value = $args->at(1);
        if (($replace !== null && ! $scope->getType($replace)->isTrue()->yes()) || $value === null) {
            return self::unknownMediaType();
        }

        $media = ScalarFold::of($scope->getType($value));

        return $media !== null && is_string($media[0])
            ? ['status' => null, 'contentType' => $media[0], 'contentTypeUnknown' => false]
            : self::unknownMediaType();
    }

    /**
     * `withHeaders(['Content-Type' => '…'])`. Only a written array can be read: a map assembled elsewhere
     * may carry the media type without saying so here, and a link that might have set one is not a link
     * that provably set none.
     *
     * @return ChainLink
     */
    private function readHeaders(ArgumentSlots $args, Scope $scope): array
    {
        $headers = $args->at(0);
        if (! $headers instanceof Node\Expr\Array_) {
            return self::unknownMediaType();
        }

        $media = ContentTypeHeader::inArray($headers, $scope);

        return ['status' => null, 'contentType' => $media, 'contentTypeUnknown' => false];
    }

    /**
     * A header link that may or may not have set the media type. Neither publishing the receiver's nor
     * inventing one is honest, so the chain reports it unknown and the response falls back to the default.
     *
     * @return ChainLink
     */
    private static function unknownMediaType(): array
    {
        return ['status' => null, 'contentType' => null, 'contentTypeUnknown' => true];
    }
}
