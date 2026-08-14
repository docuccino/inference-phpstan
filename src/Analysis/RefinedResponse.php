<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;

/**
 * A response shape {@see ResponseShapeRefiner} recovered from a return PHPStan had erased to a bare
 * `JsonResponse`/`Response`: payload, folded HTTP status, explicit content type, and — when the payload is
 * an object — the arguments it was constructed with. Emitted back as the
 * `JsonResponse<payload, status, contentType, members>` {@see ClassT} the pipeline already understands (all
 * but the first two args are ours; the `response()->json()` extension emits only those two).
 *
 * Four members keep the recovery honest. `$statusSource` is the accessor a non-literal status reads from
 * — a forwarded parameter, or an accessor on an enum parameter (`$problem->status()`) — so the call site
 * can bind it once it knows the argument, while the callee's own analysis stays call-independent and
 * memoises by symbol alone; a status that folds to neither stays permissive. `$delegates` marks a
 * `return null`/void arm: framework delegation, neither a response nor a fold failure.
 * `$payloadParamProvenance` does the same job per payload member key, and a member reading the same
 * accessor as the status becomes a {@see StatusMarkerT} so the response-building seam can fill it with
 * whatever status the response ends up documented under. Provenance is transient, never serialised —
 * binding consumes it, and anything unresolved leaves the member widened.
 *
 * `$payloadMembers` is the object-payload counterpart of the shape rewriting the other three do in place:
 * a class type has no fields to pin a folded value into, so the constructor arguments live beside it, one
 * field per SUPPLIED argument ({@see UnknownT} until something folds it). Presence in that map is itself
 * the fact worth carrying — an argument passed at this call site is in this response's body whatever the
 * schema says about optionality — so an argument that isn't supplied at a hop leaves the map entirely
 * rather than widening.
 *
 * @internal
 */
final readonly class RefinedResponse
{
    /**
     * @param  array<string, ParamAccessor>  $payloadParamProvenance  top-level payload member key → the accessor its value reads from
     */
    public function __construct(
        public ?DType $payload = null,
        public ?LiteralT $status = null,
        public ?ParamAccessor $statusSource = null,
        public ?string $contentType = null,
        public bool $delegates = false,
        public array $payloadParamProvenance = [],
        public ?ArrayShapeT $payloadMembers = null,
    ) {}

    /** A `return null`/void return: the framework handles it, so there's no response to document. */
    public static function delegation(): self
    {
        return new self(delegates: true);
    }

    /** An everything-null shape isn't worth substituting for the bare type. */
    public function isDocumentable(): bool
    {
        return $this->payload !== null || $this->status !== null || $this->contentType !== null;
    }

    /** Labels the media type recovered from a header the helper set after building the response. */
    public function withContentType(string $contentType): self
    {
        return new self($this->payload, $this->status, $this->statusSource, $contentType, $this->delegates, $this->payloadParamProvenance, $this->payloadMembers);
    }

    /** Clears {@see $statusSource} so the bound shape reads as resolved. */
    public function withBoundStatus(LiteralT $status): self
    {
        return new self($this->payload, $status, null, $this->contentType, $this->delegates, $this->payloadParamProvenance, $this->payloadMembers);
    }

    /**
     * Re-home a pass-through status onto an outer callee's parameter: the inner callee read its status from
     * parameter X, the outer call passed its own Y into X, so from out there the status reads through Y.
     */
    public function withStatusSource(?ParamAccessor $statusSource): self
    {
        return new self($this->payload, null, $statusSource, $this->contentType, $this->delegates, $this->payloadParamProvenance, $this->payloadMembers);
    }

    /**
     * @param  array<string, ParamAccessor>  $payloadParamProvenance
     */
    public function withPayload(?DType $payload, array $payloadParamProvenance): self
    {
        return new self($payload, $this->status, $this->statusSource, $this->contentType, $this->delegates, $payloadParamProvenance, $this->payloadMembers);
    }

    /**
     * The constructor arguments an object payload was built with, plus the provenance of the ones still to
     * bind. Set once, at the construction site the refiner found.
     *
     * @param  array<string, ParamAccessor>  $payloadParamProvenance
     */
    public function withPayloadMembers(ArrayShapeT $payloadMembers, array $payloadParamProvenance): self
    {
        return new self($this->payload, $this->status, $this->statusSource, $this->contentType, $this->delegates, $payloadParamProvenance, $payloadMembers);
    }

    /**
     * The pure half of binding one member — the refiner classifies the argument, this applies it:
     * `$literal` pins the member and drops the provenance, `$rehome` carries it one hop out, both null drops
     * it and leaves the member as-is (so a {@see StatusMarkerT} survives for the response seam). An object
     * payload pins into {@see $payloadMembers}, an array-shape payload into the shape itself.
     */
    public function bindMember(string $key, ?LiteralT $literal, ?ParamAccessor $rehome): self
    {
        $provenance = $this->payloadParamProvenance;
        unset($provenance[$key]);

        if ($literal !== null && $this->payloadMembers !== null) {
            return $this->withPayloadMembers(self::replaceFieldType($this->payloadMembers, $key, $literal), $provenance);
        }

        if ($literal !== null && $this->payload instanceof ArrayShapeT) {
            return $this->withPayload(self::replaceFieldType($this->payload, $key, $literal), $provenance);
        }

        if ($rehome !== null) {
            $provenance[$key] = $rehome;
        }

        return $this->withPayload($this->payload, $provenance);
    }

    /**
     * Drops a constructor argument that this call site didn't supply: it took its default, so it is not a
     * member of this response's body at all. Only object payloads can lose a member this way — an
     * array-shape payload is PHPStan's own account of the body, which stands whatever flowed into it.
     */
    public function withoutMember(string $key): self
    {
        $provenance = $this->payloadParamProvenance;
        unset($provenance[$key]);

        if ($this->payloadMembers === null) {
            return $this->withPayload($this->payload, $provenance);
        }

        return $this->withPayloadMembers(
            new ArrayShapeT(
                array_values(array_filter(
                    $this->payloadMembers->fields,
                    static fn (ArrayShapeField $field): bool => (string) $field->key !== $key,
                )),
                $this->payloadMembers->isList,
            ),
            $provenance,
        );
    }

    /**
     * Marks any member reading the same accessor as the status with a {@see StatusMarkerT} — the
     * call-independent fact that the member echoes the response status.
     *
     * @param  array<string, ParamAccessor>  $payloadParamProvenance  member key → accessor
     */
    public static function fromConstructor(?DType $payload, ?LiteralT $status, ?ParamAccessor $statusSource, ?string $contentType, array $payloadParamProvenance): self
    {
        if ($statusSource !== null && $payload instanceof ArrayShapeT) {
            foreach ($payloadParamProvenance as $key => $accessor) {
                if ($accessor->equals($statusSource)) {
                    $payload = self::replaceFieldType($payload, $key, new StatusMarkerT);
                }
            }
        }

        return new self($payload, $status, $statusSource, $contentType, false, $payloadParamProvenance);
    }

    /** Key and optionality preserved; unchanged when the key is absent. */
    private static function replaceFieldType(ArrayShapeT $shape, string $key, DType $type): ArrayShapeT
    {
        return $shape->mapFieldTypes(
            static fn (DType $current, string|int $fieldKey): DType => (string) $fieldKey === $key ? $type : $current,
        );
    }

    /**
     * Null when nothing documentable was recovered. An unfolded status or payload becomes an
     * {@see UnknownT} placeholder so the pipeline falls back to the exception's own status hint rather than
     * silently defaulting; the content-type arg appears only when explicitly recovered — unless the member
     * map follows it, which needs the slot held so the map stays fourth.
     *
     * These args are read back by the pipeline, never by PHPStan, so the count here is independent of how
     * many `@template`s `JsonResponse.stub` declares.
     */
    public function toClassT(string $fqcn): ?ClassT
    {
        if (! $this->isDocumentable()) {
            return null;
        }

        $args = [
            $this->payload ?? new UnknownT('payload not folded'),
            $this->status ?? new UnknownT('status not folded'),
        ];
        if ($this->contentType !== null) {
            $args[] = new LiteralT($this->contentType);
        } elseif ($this->payloadMembers !== null) {
            $args[] = new UnknownT('content type not recovered');
        }
        if ($this->payloadMembers !== null) {
            $args[] = $this->payloadMembers;
        }

        return new ClassT($fqcn, $args);
    }
}
