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
 * The response shape {@see ResponseShapeRefiner} recovered from a return expression that PHPStan had
 * erased to a bare `JsonResponse`/`Response` — a payload shape, a folded HTTP status, and any explicit
 * content type. It is emitted back as a `JsonResponse<payload, status, contentType>` {@see ClassT} the
 * inferred-handler pipeline already understands (the third arg is the refinement's addition; the
 * `response()->json()` extension emits only the first two).
 *
 * Two members carry recovery HONESTY (design goal: never guess):
 *   - {@see $statusSource} is the {@see ParamAccessor} the status reads from when it is not a
 *     call-independent literal — a callee parameter passed through unchanged, OR an accessor on an enum
 *     parameter (`$problem->status()`). It lets the CALL SITE bind the status once it knows the concrete
 *     argument: a foldable literal (`make($title, 422, …)`), a concrete enum case whose accessor folds
 *     (`make(ProblemType::Forbidden, …)` → `403`), or a caller parameter to re-home one hop out. The
 *     callee analysis itself stays call-independent (it memoises by callee symbol alone). A status that
 *     folds to none of these stays permissive (both null).
 *   - {@see $delegates} marks a return that yielded no response at all (`return null` / void) — the
 *     "delegate to the framework" arm, which is neither a documentable response nor a fold failure.
 *
 * A third honesty member carries VALUE-FLOW provenance for the payload body:
 *   - {@see $payloadParamProvenance} maps a top-level payload member key to the {@see ParamAccessor} its
 *     value reads from inside the helper (`['type' => $problem->value, 'status' => $problem->status(), …]`).
 *     It lets the CALL SITE fold a member exactly as the status is bound — call-independent, so the callee
 *     shape still memoises by symbol alone. A member whose accessor is the SAME as the status source is
 *     emitted as a {@see StatusMarkerT} so that when the status does not fold, the response-building seam
 *     still fills it with the concrete status the response is documented under. Provenance is TRANSIENT
 *     (never serialised): binding consumes it, and anything unresolved at emission leaves the member at
 *     its widened type.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
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

    /** The "delegates to the framework" arm — a `return null` / void return, not a response. */
    public static function delegation(): self
    {
        return new self(delegates: true);
    }

    /** Whether anything documentable was recovered (a bare, everything-null shape is not worth substituting). */
    public function isDocumentable(): bool
    {
        return $this->payload !== null || $this->status !== null || $this->contentType !== null;
    }

    /**
     * With the status source bound to a concrete literal recovered at the call site — used when a caller
     * folds the argument the callee forwards to its status (a literal, or an enum case whose accessor
     * folds). Clears {@see $statusSource} so the bound shape reads as a resolved literal.
     */
    public function withBoundStatus(LiteralT $status): self
    {
        return new self($this->payload, $status, null, $this->contentType, $this->delegates, $this->payloadParamProvenance);
    }

    /**
     * Re-home a pass-through status source onto an OUTER callee's parameter (transitive binding through a
     * hop): the inner callee forwarded its status from an accessor on parameter X, and the outer call
     * passed its own parameter Y into X, so from the outer callee's vantage the status reads through Y.
     */
    public function withStatusSource(?ParamAccessor $statusSource): self
    {
        return new self($this->payload, null, $statusSource, $this->contentType, $this->delegates, $this->payloadParamProvenance);
    }

    /**
     * With the payload body and its member→accessor provenance rewritten (used as a call site folds the
     * arguments the callee forwarded into its body). Everything else is preserved.
     *
     * @param  array<string, ParamAccessor>  $payloadParamProvenance
     */
    public function withPayload(?DType $payload, array $payloadParamProvenance): self
    {
        return new self($payload, $this->status, $this->statusSource, $this->contentType, $this->delegates, $payloadParamProvenance);
    }

    /**
     * Bind ONE provenance-tracked body member as a call site resolves the argument the callee forwarded
     * into it (pure — the refiner classifies the argument via the analysis Scope, this applies the
     * result): a folded `$literal` pins the member to that value and drops the provenance; a re-homed
     * `$rehome` accessor carries the provenance one hop out; both null drops the provenance and leaves the
     * member at its current type (a {@see StatusMarkerT} status member is thereby left for the response
     * seam to fill). A no-op when the payload is not a keyed shape or the key is absent.
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
     * A copy of the constructor-recovered parts with any body member whose value reads from the SAME
     * accessor that drives the HTTP status marked as a {@see StatusMarkerT} — the call-independent truth
     * that the member echoes the response status (pure; the refiner supplies the Scope-derived provenance
     * + status source).
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

    /**
     * A copy of a keyed array shape with one member's value type replaced (key + optionality preserved),
     * or the shape unchanged when the key is absent.
     */
    private static function replaceFieldType(ArrayShapeT $shape, string $key, DType $type): ArrayShapeT
    {
        return $shape->mapFieldTypes(
            static fn (DType $current, string|int $fieldKey): DType => (string) $fieldKey === $key ? $type : $current,
        );
    }

    /**
     * The recovered shape as a `JsonResponse<payload, status, contentType>` {@see ClassT}, or null when
     * nothing documentable was recovered. An unfolded status/payload is emitted as an {@see UnknownT}
     * placeholder so the pipeline can fall back honestly (the exception's own status hint) rather than
     * silently defaulting; the content-type arg is present only when explicitly recovered.
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
