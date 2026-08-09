<?php

declare(strict_types=1);

/*
 * Fixture-app engine runner (integration-test subprocess).
 *
 * The engine runs INSIDE the host Laravel app's process using the host app's
 * vendor — exactly how it will run in production, and how Phase 2b's workers will
 * run it. Booting it in-process from the root Pest run is not viable: Pest pulls
 * its own symfony/console, which collides with the fixture app's Laravel console
 * when both vendors are active. So the integration tests shell out to this
 * runner, which loads ONLY the fixture app's autoloader plus a hand-registered
 * PSR-4 map for the docuccino packages under test.
 *
 * Usage (one mode per invocation — each maps 1:1 onto a FixtureRunner method):
 *   php engine-runner.php analyze                   <controllerFile> <class> <method>
 *   php engine-runner.php analyze-callable          <file> <class> <method> <line> <narrowParam> <narrowType>
 *   php engine-runner.php refine-pair               <fileBudget> <file1> <class1> <method1> <file2> <class2> <method2>
 *   php engine-runner.php class-metadata            <ignored>        <class>
 *   php engine-runner.php trace-qb                  <controllerFile> <class> <method>
 *   php engine-runner.php trace-qb-enrich           <controllerFile> <class> <method>
 *   php engine-runner.php trace-rules               <file> <class> <method>
 *   php engine-runner.php trace-inline-rules        <controllerFile> <class> <method>
 *   php engine-runner.php trace-json-api-paginate   <controllerFile> <class> <method>
 *   php engine-runner.php trace-pagination-terminal <controllerFile> <class> <method>
 *   php engine-runner.php trace-created-resource    <controllerFile> <class> <method>
 *   php engine-runner.php trace-rate-limiter        <file> <ignored> <ignored> <line>
 *
 * Dispatch stays a `match ($mode)` rather than a mode => factory table: each arm is a thin visitor
 * probe carrying a wave-traceability comment, and a test-only harness does not warrant the extra
 * indirection (revisit if the mode set keeps growing).
 *
 * Emits `@@RESULT@@` followed by a single JSON line (so any incidental host
 * output before it is ignored by the caller).
 */

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Tests\Support\QueryBuilderProbe;
use Docuccino\Laravel\Integrations\ApiResources\CreatedResourceVisitor;
use Docuccino\Laravel\Integrations\FormRequest\InlineRulesVisitor;
use Docuccino\Laravel\Integrations\FormRequest\RulesMethodVisitor;
use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumn;
use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumnResolver;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderTraceVisitor;
use Docuccino\Laravel\Integrations\QueryBuilder\ScopeParameterResolver;
use Docuccino\Laravel\Integrations\RateLimit\RateLimiterLimitVisitor;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;

$repoRoot = dirname(__DIR__, 4);
$app = $repoRoot.'/tests/fixture-app/app';

require $app.'/vendor/autoload.php';

// Hand-registered PSR-4 for the packages under test — no root composer vendor is
// loaded here, so the only phpstan/php-parser in play is the fixture app's.
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $map = [
        'Docuccino\\Attributes\\' => $repoRoot.'/packages/attributes/src/',
        'Docuccino\\Core\\' => $repoRoot.'/packages/core/src/',
        'Docuccino\\Inference\\PhpStan\\Tests\\' => $repoRoot.'/packages/inference-phpstan/tests/',
        'Docuccino\\Inference\\PhpStan\\' => $repoRoot.'/packages/inference-phpstan/src/',
        // Several adapter-side trace visitors (QB, json-api-paginate, pagination terminal, rules,
        // created-resource) run here to prove terminal/receiver matching + rule/column recovery on the
        // REAL engine. They import only core + php-parser (+ their own dep-free facts/config), so the
        // fixture app's phpstan/php-parser stays the only one in play — hence mapping all of
        // `Docuccino\Laravel\` here is sound (this was one class at spike-d; it is now the norm).
        'Docuccino\\Laravel\\' => $repoRoot.'/packages/laravel/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $dir.str_replace('\\', '/', $relative).'.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';
$class = $argv[3] ?? '';
$method = $argv[4] ?? '';
$line = (int) ($argv[5] ?? 0);
$narrowParam = ($argv[6] ?? '') === '' ? null : $argv[6];
$narrowType = ($argv[7] ?? '') === '' ? null : $argv[7];

// A unique tmp dir per invocation — PID alone is reused across the many subprocesses a
// fixture-group run forks, violating RuntimeConfig's "MUST be isolated per invocation"
// contract; uniqid() makes it collision-free. Cleaned up on shutdown so runs don't leak.
$tmp = sys_get_temp_dir().'/docuccino-runner-'.getmypid().'-'.uniqid('', true);
@mkdir($tmp, 0777, true);

register_shutdown_function(static function () use ($tmp): void {
    if (! is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($tmp);
});

// The refine-pair mode drives the engine with a deliberately tiny per-analysis file budget (argv[2]) so
// a shared helper truncates on a budget-spending path and has headroom on a direct one — the
// determinism/truncation memo guard (ResponseShapeRefiner). Every other mode keeps the real default (40).
$engineConfig = EngineConfig::forProjectWithVendor($app.'/vendor', $app.'/app');
if ($mode === 'refine-pair') {
    $engineConfig = new EngineConfig(
        $engineConfig->projectPaths,
        $engineConfig->knownThrowers,
        $engineConfig->traceDepth,
        $engineConfig->throwDepth,
        max(1, (int) ($argv[2] ?? 2)),
        $engineConfig->vendorPath,
    );
}

$engine = (new PhpStanEngineFactory)->create(
    // PRIME scope (bodies preserved) covers `modules/` too, so a Query class OUTSIDE the descend
    // scope is not body-stripped when the QB trace follows a `$query->query()` hop into it.
    new RuntimeConfig($app, $tmp, PHP_VERSION_ID, [$app.'/app', $app.'/modules']),
    // DESCEND scope stays `app/` (throws/inline-rules bounded); vendorPath lets the QB trace follow a
    // QueryBuilder-return-type hop into the primed `modules/` Query class, never into vendor.
    $engineConfig,
);

$ref = new ActionRef($file, $class === '' ? null : $class, $method);

$result = match ($mode) {
    'analyze' => $engine->analyzeAction($ref)->toArray(),
    'analyze-callable' => $engine->analyzeCallable(new CallableRef(
        $file,
        $class === '' ? null : $class,
        $method === '' ? null : $method,
        $line,
        $narrowParam,
        $narrowType,
    ))->toArray(),
    // Analyse two callables through ONE engine (shared per-callee memo) under the tiny budget: the
    // determinism guard for the refiner's "never memoise a budget-truncated shape" rule.
    'refine-pair' => (static function () use ($engine, $app, $argv): array {
        $analyse = static fn (string $relPath, string $class, string $method): array => $engine->analyzeCallable(
            new CallableRef($app.'/'.$relPath, $class === '' ? null : $class, $method === '' ? null : $method),
        )->toArray();

        return [
            'first' => $analyse($argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''),
            'second' => $analyse($argv[6] ?? '', $argv[7] ?? '', $argv[8] ?? ''),
        ];
    })(),
    'class-metadata' => $engine->classMetadata(new ClassRef($class))->toArray(),
    'trace-qb' => (static function () use ($engine, $ref): array {
        $probe = new QueryBuilderProbe;
        $engine->trace($ref, $probe);

        return [
            'filters' => $probe->allowedFilters,
            'sorts' => $probe->allowedSorts,
            'default' => $probe->defaultSort,
            'terminals' => $probe->terminals,
            'paginates' => $probe->paginates(),
            'perPage' => $probe->recoveredPerPage(),
            'outermost' => $probe->outermostTerminal()['terminal'] ?? null,
        ];
    })(),
    'trace-qb-enrich' => (static function () use ($engine, $ref): array {
        // The REAL QueryBuilder trace visitor + the REAL cast-recovery resolver, run inside the host
        // app's process where its models/enums are autoloadable: proves an enum-cast column recovers
        // to its emitted enum-filter shape (backing values + case descriptions) end-to-end.
        $visitor = new QueryBuilderTraceVisitor;
        $report = $engine->trace($ref, $visitor);
        $facts = $visitor->facts;

        $resolver = new FilterColumnResolver;
        $scopes = new ScopeParameterResolver;
        $filters = array_map(static function (QbEntry $filter) use ($resolver, $scopes, $facts): array {
            $model = $facts->subjectModel;
            // Mirror the extension's per-kind typing: a resolved column (exact/callback) off the model
            // cast, a scope value parameter off its scope method — proving both against the real engine.
            $column = match (true) {
                // A project-factory filter carrying a backed-enum class-string types off it DIRECTLY
                // (no model needed) — the ListFilters-style recovery.
                $filter->factoryEnum !== null => FilterColumn::enum(
                    $filter->factoryEnum,
                    ($f = EnumReflection::file($filter->factoryEnum)) !== null ? [$f] : [],
                ),
                $model === null => null,
                $filter->kind === 'scope' => $scopes->resolve($model, $filter->name),
                in_array($filter->kind, ['exact', 'callback'], true) && $filter->typeColumn !== null => $resolver->resolve($model, $filter->typeColumn),
                // A non-enum project factory (boolean/uuid) types off its key column via the model cast.
                $filter->factoryClass !== null && $filter->typeColumn !== null => $resolver->resolve($model, $filter->typeColumn),
                default => null,
            };

            return [
                'name' => $filter->name,
                'kind' => $filter->kind,
                'factoryEnum' => $filter->factoryEnum,
                'factoryClass' => $filter->factoryClass,
                // The recovered leading comment (real-engine proof that PHPStan's parser attributes
                // it to the array item the same way ParserFactory does → an override description).
                'comment' => $filter->comment,
                'columnKind' => $column?->kind,
                'enum' => $column?->enum,
                'values' => $column?->enum !== null ? EnumReflection::values($column->enum) : [],
                'descriptions' => $column?->enum !== null ? EnumReflection::descriptions($column->enum) : [],
                'dependencyBasenames' => array_map('basename', $column?->dependencyFiles ?? []),
                'scalarSchema' => $column?->scalarSchema,
            ];
        }, $facts->filters);

        // visitedBasenames proves cache soundness: a Query class reached only via the return-type
        // follow-beyond still lands in the trace's dependency files (edit it → fragment invalidates).
        return [
            'subjectModel' => $facts->subjectModel,
            'filters' => $filters,
            'sorts' => array_map(static fn (QbEntry $s): string => $s->name, $facts->sorts),
            'visitedBasenames' => array_map('basename', $report->dependencyFiles),
        ];
    })(),
    'trace-rules' => (static function () use ($engine, $ref): array {
        // The REAL RulesMethodVisitor runs in the engine subprocess: it must recover a rules()
        // method's returned array with AST-level constant folding so Rule::enum(...) descriptors
        // survive (validation §1). Returns each field's recovered rule names + params, plus the
        // fields present but unrecoverable.
        $visitor = new RulesMethodVisitor;
        $engine->trace($ref, $visitor);

        $fields = [];
        foreach ($visitor->ruleSet()->fields as $field => $rules) {
            $fields[$field] = array_map(static fn ($rule): array => [
                'name' => $rule->name,
                'parameters' => $rule->parameters,
                'note' => $rule->note,
            ], $rules);
        }

        return ['fields' => $fields, 'unrecoverable' => $visitor->unrecoverableFields()];
    })(),
    'trace-inline-rules' => (static function () use ($engine, $ref): array {
        // The REAL InlineRulesVisitor traces the CONTROLLER action; the engine's bounded descent must
        // reach a `Validator::make($data, [...])` call inside a Queries class one hop away and recover
        // its rule array (the modular GET-params validation pattern).
        $visitor = new InlineRulesVisitor;
        $engine->trace($ref, $visitor);

        $fields = [];
        foreach ($visitor->ruleSet()->fields as $field => $rules) {
            $fields[$field] = array_map(static fn ($rule): array => [
                'name' => $rule->name,
                'parameters' => $rule->parameters,
                'note' => $rule->note,
            ], $rules);
        }

        return ['fields' => $fields, 'unrecoverable' => $visitor->unrecoverableFields()];
    })(),
    'trace-json-api-paginate' => (static function () use ($engine, $ref): array {
        // The shared PaginationTerminalVisitor recovers the jsonPaginate terminal + its outermost
        // int args (jsonPaginate(?maxResults, ?defaultSize)) on the real engine.
        $visitor = new PaginationTerminalVisitor(['jsonPaginate' => 'length']);
        $engine->trace($ref, $visitor);

        return [
            'paginates' => $visitor->paginates,
            'maxResults' => $visitor->outermostArgs[0] ?? null,
            'defaultSize' => $visitor->outermostArgs[1] ?? null,
        ];
    })(),
    'trace-pagination-terminal' => (static function () use ($engine, $ref): array {
        // The resource paginating terminals — proves the shared visitor detects paginate/
        // simplePaginate/cursorPaginate on a real builder receiver at chain depth (Wave C item 1).
        $visitor = new PaginationTerminalVisitor([
            'paginate' => 'length',
            'simplePaginate' => 'simple',
            'cursorPaginate' => 'cursor',
        ]);
        $engine->trace($ref, $visitor);

        return ['paginates' => $visitor->paginates, 'kind' => $visitor->kind];
    })(),
    'trace-rate-limiter' => (static function () use ($engine, $file, $line): array {
        // The REAL RateLimiterLimitVisitor over a named limiter's RateLimiter::for closure located by
        // line — proves the engine's closure trace folds an idiomatic `fn ($r) => Limit::perMinute(60)
        // ->by(…)` arrow limiter to concrete numbers (small-integrations §1 feasibility, Wave D item 4).
        $visitor = new RateLimiterLimitVisitor;
        $engine->trace(new ActionRef($file, null, '{closure}', $line), $visitor);
        $limit = $visitor->limit;

        return [
            'resolved' => $limit->resolved(),
            'bailed' => $limit->bailed,
            'returnsSeen' => $limit->returnsSeen,
            'maxAttempts' => $limit->maxAttempts,
            'decaySeconds' => $limit->decaySeconds,
        ];
    })(),
    'trace-created-resource' => (static function () use ($engine, $ref): array {
        // Proves the CreatedResourceVisitor recognises a resource wrapping a real Model::create() on
        // the real engine — the 201 status recovery (Wave C item 4).
        $visitor = new CreatedResourceVisitor;
        $engine->trace($ref, $visitor);

        return ['created' => $visitor->created];
    })(),
    default => ['error' => 'unknown mode: '.$mode],
};

fwrite(STDOUT, "\n@@RESULT@@".json_encode($result, JSON_THROW_ON_ERROR)."\n");
