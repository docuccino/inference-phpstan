<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * The user-extensible registry of Laravel-semantic throwers (design §6, layer
 * 2). Dual role (Spike C finding B): it *enriches* explicit stubbed points with
 * a status (`authorize` → 403, `validate` → 422) and *rescues* still-implicit
 * forwarders by callee name (static `Model::findOrFail` surfaces only as an
 * implicit bare `Throwable`, so the registry restores ModelNotFoundException/404
 * as `likely`).
 *
 * Immutable + additive: `withFunction()` / `withMethod()` return a new registry,
 * so users layer their own throwers on top of the defaults.
 *
 * @internal Engine implementation detail — not part of the public inference surface (see inference-embedding.md §Public surface).
 */
final class KnownThrowers
{
    public const HTTP_EXCEPTION = 'Symfony\\Component\\HttpKernel\\Exception\\HttpException';

    public const AUTHORIZATION_EXCEPTION = 'Illuminate\\Auth\\Access\\AuthorizationException';

    public const MODEL_NOT_FOUND_EXCEPTION = 'Illuminate\\Database\\Eloquent\\ModelNotFoundException';

    public const VALIDATION_EXCEPTION = 'Illuminate\\Validation\\ValidationException';

    /**
     * @param  array<string, KnownThrower>  $functions  global function name → thrower
     * @param  array<string, KnownThrower>  $methods  method name → thrower
     */
    public function __construct(
        private readonly array $functions = [],
        private readonly array $methods = [],
    ) {}

    public static function default(): self
    {
        return new self(
            functions: [
                'abort' => KnownThrower::withStatusFromArg(self::HTTP_EXCEPTION, 0),
                'abort_if' => KnownThrower::withStatusFromArg(self::HTTP_EXCEPTION, 1),
                'abort_unless' => KnownThrower::withStatusFromArg(self::HTTP_EXCEPTION, 1),
            ],
            methods: [
                'authorize' => KnownThrower::withStatus(self::AUTHORIZATION_EXCEPTION, 403),
                'authorizeForUser' => KnownThrower::withStatus(self::AUTHORIZATION_EXCEPTION, 403),
                'findOrFail' => KnownThrower::withStatus(self::MODEL_NOT_FOUND_EXCEPTION, 404),
                'firstOrFail' => KnownThrower::withStatus(self::MODEL_NOT_FOUND_EXCEPTION, 404),
                'sole' => KnownThrower::withStatus(self::MODEL_NOT_FOUND_EXCEPTION, 404),
                'validate' => KnownThrower::withStatus(self::VALIDATION_EXCEPTION, 422),
            ],
        );
    }

    public function withFunction(string $name, KnownThrower $thrower): self
    {
        return new self([...$this->functions, $name => $thrower], $this->methods);
    }

    public function withMethod(string $name, KnownThrower $thrower): self
    {
        return new self($this->functions, [...$this->methods, $name => $thrower]);
    }

    public function forFunction(string $name): ?KnownThrower
    {
        return $this->functions[$name] ?? null;
    }

    public function forMethod(string $name): ?KnownThrower
    {
        return $this->methods[$name] ?? null;
    }

    /**
     * The exception-FQCN → fixed-status map, derived from every registered
     * thrower that declares a fixed status. This is the SINGLE source of
     * exception-status knowledge: the throw analyzer consults it to enrich an
     * explicit throw (layer 1) with the same status the registry uses to rescue
     * an implicit forwarder (layer 2), so a user's `withMethod()` thrower enriches
     * both layers rather than only the rescue path. Throwers that fold their
     * status from a call argument (`abort`) carry no fixed status and are absent.
     *
     * @return array<string, int>
     */
    public function knownStatuses(): array
    {
        $map = [];
        foreach ([...array_values($this->functions), ...array_values($this->methods)] as $thrower) {
            if ($thrower->fixedStatus !== null) {
                $map[$thrower->exceptionFqcn] = $thrower->fixedStatus;
            }
        }

        return $map;
    }

    /** The fixed HTTP status for an exactly-matching exception FQCN, or null. */
    public function statusForExceptionFqcn(string $fqcn): ?int
    {
        return $this->knownStatuses()[$fqcn] ?? null;
    }
}
