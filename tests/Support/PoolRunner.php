<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use RuntimeException;

/**
 * Drives the {@see pool-runner.php} subprocess (parent orchestrator + K workers)
 * against `tests/fixture-app/app/` and returns its result payload as a raw JSON
 * string — the byte-for-byte artifact the determinism invariants compare.
 */
final class PoolRunner
{
    private static function runner(): string
    {
        return dirname(__DIR__).'/bin/pool-runner.php';
    }

    public static function available(): bool
    {
        return FixtureRunner::available() && is_file(self::runner());
    }

    /**
     * Run the pool and return the exact JSON line it emitted (keyed by action id,
     * sorted). `poisonSymbol` crashes that action's worker to exercise containment.
     */
    public static function run(
        int $workers,
        int $maxActionsPerWorker = 50,
        string $cacheDir = '',
        ?string $poisonSymbol = null,
    ): string {
        $env = $poisonSymbol !== null && $poisonSymbol !== ''
            ? 'DOCUCCINO_POISON_SYMBOL='.escapeshellarg($poisonSymbol).' '
            : '';

        $command = $env.implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            self::runner(),
            (string) $workers,
            (string) $maxActionsPerWorker,
            $cacheDir,
        ])).' 2>/dev/null';

        $output = shell_exec($command);
        if (! is_string($output) || ! str_contains($output, '@@RESULT@@')) {
            throw new RuntimeException('pool-runner produced no result: '.var_export($output, true));
        }

        return trim(substr($output, strpos($output, '@@RESULT@@') + strlen('@@RESULT@@')));
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
