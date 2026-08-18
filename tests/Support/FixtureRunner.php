<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use RuntimeException;

/**
 * Drives the {@see engine-runner.php} subprocess against `tests/fixture-app/app/` and decodes its JSON
 * result. This keeps the fixture app's Laravel/Larastan out of the Pest process (they clash over
 * symfony/console) and mirrors how the engine really runs — inside the host app's own process.
 */
final class FixtureRunner
{
    public static function appRoot(): string
    {
        return dirname(__DIR__, 4).'/tests/fixture-app/app';
    }

    private static function runner(): string
    {
        return dirname(__DIR__).'/bin/engine-runner.php';
    }

    public static function available(): bool
    {
        $app = self::appRoot();

        return is_file($app.'/vendor/autoload.php')
            && is_file($app.'/vendor/larastan/larastan/extension.neon')
            && is_file($app.'/app/Http/Controllers/SpikeController.php')
            && is_file(self::runner());
    }

    public static function path(string $relative): string
    {
        return self::appRoot().'/'.ltrim($relative, '/');
    }

    /**
     * @return array<string, mixed>
     */
    public static function analyze(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('analyze', self::path($controllerRelPath), $class, $method);
    }

    /**
     * As {@see analyze()}, but with an application PHPStan config file handed to the builder — the
     * `engine.neon` escape hatch, all the way through to the generated neon's `includes`.
     *
     * @return array<string, mixed>
     */
    public static function analyzeWithConfig(string $controllerRelPath, string $class, string $method, string $userNeon): array
    {
        return self::invoke('analyze-with-config', self::path($controllerRelPath), $class, $method, $userNeon);
    }

    /**
     * @return array<string, mixed>
     */
    public static function traceQb(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('trace-qb', self::path($controllerRelPath), $class, $method);
    }

    /**
     * Trace a controller with the real QueryBuilderTraceVisitor, then enrich its exact filters with the
     * real FilterColumnResolver: returns the recovered subject model plus, per filter, the resolved
     * column cast shape (enum FQCN + backing values + case descriptions, or a native scalar schema).
     *
     * @return array<string, mixed>
     */
    public static function traceQbEnrich(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('trace-qb-enrich', self::path($controllerRelPath), $class, $method);
    }

    /**
     * Trace a class's `rules()` with the real RulesMethodVisitor: returns each field's rule
     * names/params/note (so a `Rule::enum(...)` descriptor's backing values + FQCN are visible) plus
     * the fields that are present but unrecoverable.
     *
     * @return array<string, mixed>
     */
    public static function traceRules(string $relPath, string $class, string $method): array
    {
        return self::invoke('trace-rules', self::path($relPath), $class, $method);
    }

    /**
     * Trace a controller action with the real InlineRulesVisitor: the engine's bounded descent has to
     * reach a `Validator::make($data, [...])` inside a Queries class one hop away and recover its rule
     * array. Same shape as {@see traceRules()}.
     *
     * @return array<string, mixed>
     */
    public static function traceInlineRules(string $relPath, string $class, string $method): array
    {
        return self::invoke('trace-inline-rules', self::path($relPath), $class, $method);
    }

    /**
     * Trace a controller with the shared PaginationTerminalVisitor over the `jsonPaginate` terminal:
     * returns whether it reached the terminal, plus the folded per-call-site overrides
     * (`maxResults`/`defaultSize`, from the outermost call's int args).
     *
     * @return array<string, mixed>
     */
    public static function traceJsonApiPaginate(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('trace-json-api-paginate', self::path($controllerRelPath), $class, $method);
    }

    /**
     * Trace a controller with the PaginationTerminalVisitor over the resource paginating terminals:
     * returns whether it reached a `paginate`/`simplePaginate`/`cursorPaginate` terminal, and its kind.
     *
     * @return array<string, mixed>
     */
    public static function tracePaginationTerminal(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('trace-pagination-terminal', self::path($controllerRelPath), $class, $method);
    }

    /**
     * Trace a controller with the CreatedResourceVisitor: returns whether the action returns a resource
     * wrapped directly around a `Model::create(...)` — i.e. a 201.
     *
     * @return array<string, mixed>
     */
    public static function traceCreatedResource(string $controllerRelPath, string $class, string $method): array
    {
        return self::invoke('trace-created-resource', self::path($controllerRelPath), $class, $method);
    }

    /**
     * Trace a closure located by start line with the {@see ClosureReturnProbe}: returns one entry per
     * return expression the engine handed over, with its node kind and the scope's type for it.
     *
     * @return array<string, mixed>
     */
    public static function traceClosure(string $relPath, int $line): array
    {
        return self::invoke('trace-closure', self::path($relPath), '', '{closure}', (string) $line);
    }

    /**
     * Serialized {@see ClassMetadata} for a class (property names + reflected types). The file argument
     * is unused in this mode.
     *
     * @return array<string, mixed>
     */
    public static function classMetadata(string $class): array
    {
        return self::invoke('class-metadata', '', $class, '');
    }

    /**
     * Serialized {@see ActionAnalysis} for a non-action callable: either a method (pass
     * `$class`+`$method`, optionally a narrowing `$param`+`$narrowType`) or a closure located by line
     * (pass `$line` with an empty `$class`/`$method`).
     *
     * @return array<string, mixed>
     */
    public static function analyzeCallable(
        string $relPath,
        string $class,
        string $method,
        int $line = 0,
        string $param = '',
        string $narrowType = '',
    ): array {
        return self::invoke('analyze-callable', self::path($relPath), $class, $method, (string) $line, $param, $narrowType);
    }

    /**
     * Analyse two callables through one engine under tiny descent bounds, so a shared helper truncates on
     * a bound-spending path and has headroom on a direct one — the refiner's "only serve a memo entry the
     * caller could have earned" guard, on either bound. Returns `{first, second}` analyses.
     *
     * This mode's paths go to the runner as-is rather than through {@see path()}, so pass them relative
     * to the fixture app root (e.g. `app/Support/BudgetRenderer.php`).
     *
     * @param  array{0: string, 1: string, 2: string}  $first  [relPath, class, method]
     * @param  array{0: string, 1: string, 2: string}  $second  [relPath, class, method]
     * @return array<string, mixed>
     */
    public static function refinePair(int $fileBudget, int $traceDepth, array $first, array $second): array
    {
        return self::invoke(
            'refine-pair',
            (string) $fileBudget,
            (string) $traceDepth,
            $first[0],
            $first[1],
            $first[2],
            $second[0],
            $second[1],
            $second[2],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoke(string $mode, string $file, string $class, string $method, string ...$extra): array
    {
        // The engine cold-compiles a PHPStan container and analyses the whole Laravel+Larastan app, so
        // it needs a generous memory ceiling. pcov is off because subprocess coverage is invisible
        // anyway (docs/testing.md) and only slows the run down. stderr goes to a temp file so an
        // OOM/fatal surfaces in the failure message rather than an opaque "produced no result: ''".
        $stderrFile = tempnam(sys_get_temp_dir(), 'docuccino-runner-stderr-');

        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-d', 'memory_limit=2G',
            '-d', 'pcov.enabled=0',
            self::runner(),
            $mode,
            $file,
            $class,
            $method,
            ...$extra,
        ])).($stderrFile !== false ? ' 2>'.escapeshellarg($stderrFile) : ' 2>/dev/null');

        $output = shell_exec($command);

        $stderr = '';
        if ($stderrFile !== false) {
            $stderr = (string) @file_get_contents($stderrFile);
            @unlink($stderrFile);
        }

        if (! is_string($output) || ! str_contains($output, '@@RESULT@@')) {
            throw new RuntimeException(sprintf(
                "engine-runner produced no result.\n  mode: %s\n  stdout: %s\n  stderr: %s",
                $mode,
                var_export($output, true),
                $stderr === '' ? '(empty)' : trim($stderr),
            ));
        }

        $json = substr($output, strpos($output, '@@RESULT@@') + strlen('@@RESULT@@'));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
