<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * The engine's registry of Laravel-semantic throwers — layer 2 of the throw analysis. It both enriches
 * explicit stubbed points with a status (`authorize` → 403, `validate` → 422) and rescues still-implicit
 * forwarders by callee name (static `Model::findOrFail` surfaces only as an implicit bare `Throwable`, so
 * the registry restores ModelNotFoundException/404 at `likely`).
 *
 * Immutable and additive — `withFunction()`/`withMethod()` return a new registry — but that is internal
 * wiring rather than a user surface. A bare name is a guess that stands down the moment a body is
 * readable, so publishing it would freeze a rescue heuristic as API; a project teaches the analysis
 * about its own code through its own PHPStan config instead (`engine.neon`, design §7).
 *
 * @internal
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
     * Exception FQCN → fixed status, from every thrower that declares one. The single source of
     * exception-status knowledge: layer 1 enriches an explicit throw from the same map layer 2 uses to
     * rescue an implicit forwarder, so a user's `withMethod()` thrower improves both. Throwers that fold
     * their status from an argument (`abort`) have no fixed status and don't appear.
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

    /** Exact FQCN match only — subclass inheritance is the throw analyzer's job. */
    public function statusForExceptionFqcn(string $fqcn): ?int
    {
        return $this->knownStatuses()[$fqcn] ?? null;
    }
}
