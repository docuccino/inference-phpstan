<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Analysis;

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\StatusMarkerT;
use Docuccino\Core\Inference\DType\UnknownT;

/**
 * A response shape {@see ResponseShapeRefiner} recovered from a return PHPStan had erased to a bare
 * `JsonResponse`/`Response`: payload, folded HTTP status, explicit content type. Emitted back as the
 * `JsonResponse<payload, status, contentType>` {@see ClassT} the pipeline already understands (the third
 * arg is ours; the `response()->json()` extension emits only the first two).
 *
 * Three members keep the recovery honest. `$statusSource` is the accessor a non-literal status reads from
 * — a forwarded parameter, or an accessor on an enum parameter (`$problem->status()`) — so the call site
 * can bind it once it knows the argument, while the callee's own analysis stays call-independent and
 * memoises by symbol alone; a status that folds to neither stays permissive. `$delegates` marks a
 * `return null`/void arm: framework delegation, neither a response nor a fold failure.
 * `$payloadParamProvenance` does the same job per payload member key, and a member reading the same
 * accessor as the status becomes a {@see StatusMarkerT} so the response-building seam can fill it with
 * whatever status the response ends up documented under. Provenance is transient, never serialised —
 * binding consumes it, and anything unresolved leaves the member widened.
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

    /** Clears {@see $statusSource} so the bound shape reads as resolved. */
    public function withBoundStatus(LiteralT $status): self
    {
        return new self($this->payload, $status, null, $this->contentType, $this->delegates, $this->payloadParamProvenance);
    }

    /**
     * Re-home a pass-through status onto an outer callee's parameter: the inner callee read its status from
     * parameter X, the outer call passed its own Y into X, so from out there the status reads through Y.
     */
    public function withStatusSource(?ParamAccessor $statusSource): self
    {
        return new self($this->payload, null, $statusSource, $this->contentType, $this->delegates, $this->payloadParamProvenance);
    }

    /**
     * @param  array<string, ParamAccessor>  $payloadParamProvenance
     */
    public function withPayload(?DType $payload, array $payloadParamProvenance): self
    {
        return new self($payload, $this->status, $this->statusSource, $this->contentType, $this->delegates, $payloadParamProvenance);
    }

    /**
     * The pure half of binding one member — the refiner classifies the argument, this applies it:
     * `$literal` pins the member and drops the provenance, `$rehome` carries it one hop out, both null drops
     * it and leaves the member as-is (so a {@see StatusMarkerT} survives for the response seam).
     */
    public function bindMember(string $key, ?LiteralT $literal, ?ParamAccessor $rehome): self
    {
        $provenance = $this->payloadParamProvenance;
        unset($provenance[$key]);

        if ($literal !== null && $this->payload instanceof ArrayShapeT) {
            return $this->withPayload(self::replaceFieldType($this->payload, $key, $literal), $provenance);
        }

        if ($rehome !== null) {
            $provenance[$key] = $rehome;
        }

        return $this->withPayload($this->payload, $provenance);
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
     * silently defaulting; the content-type arg appears only when explicitly recovered.
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
        }

        return new ClassT($fqcn, $args);
    }
}
