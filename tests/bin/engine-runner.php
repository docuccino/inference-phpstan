<?php

declare(strict_types=1);

/*
 * Fixture-app engine runner (integration-test subprocess).
 *
 * The engine runs inside the host Laravel app's process using the host app's vendor,
 * exactly as it does in production. Booting it in-process from the root Pest run isn't
 * viable: Pest pulls its own symfony/console, which collides with the fixture app's
 * Laravel console when both vendors are active. So the integration tests shell out to
 * this runner, which loads only the fixture app's autoloader plus a hand-registered
 * PSR-4 map for the docuccino packages under test.
 *
 * Usage (one mode per invocation — each maps 1:1 onto a FixtureRunner method):
 *   php engine-runner.php analyze                   <controllerFile> <class> <method>
 *   php engine-runner.php analyze-with-config       <controllerFile> <class> <method> <userNeon>
 *   php engine-runner.php analyze-callable          <file> <class> <method> <line> <narrowParam> <narrowType>
 *   php engine-runner.php refine-pair               <fileBudget> <traceDepth> <file1> <class1> <method1> <file2> <class2> <method2>
 *   php engine-runner.php class-metadata            <ignored>        <class>
 *   php engine-runner.php trace-qb                  <controllerFile> <class> <method>
 *   php engine-runner.php trace-qb-replay           <controllerFile> <class> <method>
 *   php engine-runner.php trace-qb-enrich           <controllerFile> <class> <method>
 *   php engine-runner.php trace-rules               <file> <class> <method>
 *   php engine-runner.php trace-inline-rules        <controllerFile> <class> <method>
 *   php engine-runner.php trace-json-api-paginate   <controllerFile> <class> <method>
 *   php engine-runner.php trace-pagination-terminal <controllerFile> <class> <method>
 *   php engine-runner.php trace-created-resource    <controllerFile> <class> <method>
 *   php engine-runner.php trace-file-responses      <controllerFile> <class> <method>
 *   php engine-runner.php trace-closure             <file> <ignored> <ignored> <line>
 *
 * Dispatch stays a `match ($mode)` rather than a mode => factory table — each arm is a thin
 * visitor probe and a test-only harness doesn't warrant the indirection. Revisit if the mode
 * set keeps growing.
 *
 * Emits `@@RESULT@@` followed by a single JSON line, so any incidental host output before
 * it is ignored by the caller.
 */

use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Inference\PhpStan\Analysis\EngineConfig;
use Docuccino\Inference\PhpStan\Analysis\PhpStanEngineFactory;
use Docuccino\Inference\PhpStan\Analysis\PhpStanTypeEngineBuilder;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapter;
use Docuccino\Inference\PhpStan\Runtime\RuntimeAdapterFactory;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Tests\Support\ClosureReturnProbe;
use Docuccino\Inference\PhpStan\Tests\Support\CountingRuntimeAdapter;
use Docuccino\Inference\PhpStan\Tests\Support\QueryBuilderProbe;
use Docuccino\Laravel\Extensions\FileResponseCall;
use Docuccino\Laravel\Extensions\FileResponseVisitor;
use Docuccino\Laravel\Integrations\ApiResources\CreatedResourceVisitor;
use Docuccino\Laravel\Integrations\FormRequest\InlineRulesVisitor;
use Docuccino\Laravel\Integrations\FormRequest\RulesMethodVisitor;
use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumn;
use Docuccino\Laravel\Integrations\QueryBuilder\FilterColumnResolver;
use Docuccino\Laravel\Integrations\QueryBuilder\QbBuilderRoots;
use Docuccino\Laravel\Integrations\QueryBuilder\QbEntry;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderTraceVisitor;
use Docuccino\Laravel\Integrations\QueryBuilder\ScopeParameterResolver;
use Docuccino\Laravel\Integrations\Support\PaginationTerminalVisitor;

$repoRoot = dirname(__DIR__, 4);
$app = $repoRoot.'/tests/fixture-app/app';

require $app.'/vendor/autoload.php';

// Hand-registered PSR-4 for the packages under test — no root composer vendor is loaded
// here, so the only phpstan/php-parser in play is the fixture app's.
spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $map = [
        'Docuccino\\Attributes\\' => $repoRoot.'/php/attributes/src/',
        'Docuccino\\Core\\' => $repoRoot.'/php/core/src/',
        'Docuccino\\Inference\\PhpStan\\Tests\\' => $repoRoot.'/php/inference-phpstan/tests/',
        'Docuccino\\Inference\\PhpStan\\' => $repoRoot.'/php/inference-phpstan/src/',
        // Several adapter-side trace visitors (QB, json-api-paginate, pagination terminal, rules,
        // created-resource) run here to prove terminal/receiver matching and rule/column recovery on
        // the real engine. They import only core + php-parser (plus their own dep-free facts/config),
        // so the fixture app's phpstan/php-parser stays the only one in play — which is what makes
        // mapping all of `Docuccino\Laravel\` here sound.
        'Docuccino\\Laravel\\' => $repoRoot.'/php/laravel/src/',
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

// A unique tmp dir per invocation. PID alone is reused across the many subprocesses a
// fixture-group run forks, which breaks RuntimeConfig's isolated-per-invocation contract;
// uniqid() makes it collision-free. Cleaned up on shutdown so runs don't leak.
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

// refine-pair drives the engine with a tiny per-analysis file budget (argv[2]) and descent depth
// (argv[3]) so a shared helper truncates on a budget-spending path and has headroom on a direct one —
// the ResponseShapeRefiner's memo-headroom guard, on either bound. Every other mode keeps the real
// defaults (40 / 4).
$engineConfig = EngineConfig::forProjectWithVendor($app.'/vendor', $app.'/app');
if ($mode === 'refine-pair') {
    $engineConfig = new EngineConfig(
        $engineConfig->projectPaths,
        $engineConfig->knownThrowers,
        max(1, (int) ($argv[3] ?? 4)),
        $engineConfig->throwDepth,
        max(1, (int) ($argv[2] ?? 2)),
        $engineConfig->vendorPath,
    );
}

// Every mode but refine-pair boots through the package's public entry point — the same
// TypeEngineBuilder seam an adapter probes for — so the real path proves the seam too. refine-pair
// needs a hand-built EngineConfig (the file budget above), which the builder deliberately doesn't
// expose, so it goes through the factory directly.
//
// Prime scope (bodies preserved) covers `modules/` too, so a Query class outside the descend scope
// isn't body-stripped when the QB trace follows a `$query->query()` hop into it. Descend scope stays
// `app/` (throws/inline-rules bounded); vendorPath lets the QB trace follow a QueryBuilder-return-type
// hop into the primed `modules/` Query class, never into vendor.
//
// analyze-with-config hands the builder a user neon (argv[5]) — the app's own PHPStan config, which
// the generated one includes.
//
// trace-qb-replay counts the passes each file costs, so it builds through an adapter factory that wraps
// what the real one produced. The rest of the wiring is the real builder's, which is the point: the count
// only comes out at 1 if PhpStanEngineFactory gave the method harvest and every trace the SAME recorder.
/** @var list<CountingRuntimeAdapter> $countedAdapters */
$countedAdapters = [];
$adapterFactory = new class($countedAdapters) extends RuntimeAdapterFactory
{
    /** @param  list<CountingRuntimeAdapter>  $adapters */
    public function __construct(private array &$adapters) {}

    public function create(RuntimeConfig $config): RuntimeAdapter
    {
        return $this->adapters[] = new CountingRuntimeAdapter(parent::create($config));
    }
};

$engine = $mode === 'refine-pair'
    ? (new PhpStanEngineFactory)->create(
        new RuntimeConfig($app, $tmp, PHP_VERSION_ID, [$app.'/app', $app.'/modules']),
        $engineConfig,
    )
    : (new PhpStanTypeEngineBuilder($mode === 'trace-qb-replay'
        ? new PhpStanEngineFactory($adapterFactory)
        : new PhpStanEngineFactory))->build(
            projectRoot: $app,
            tmpDir: $tmp,
            vendorPath: $app.'/vendor',
            primePaths: [$app.'/app', $app.'/modules'],
            descendPaths: [$app.'/app'],
            configFile: $mode === 'analyze-with-config' ? ($argv[5] ?? null) : null,
        );

$ref = new ActionRef($file, $class === '' ? null : $class, $method);

// Shared by trace-qb and trace-qb-replay, whose whole point is that the two harvests are comparable.
$qbHarvest = static function () use ($engine, $ref): array {
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
};

$result = match ($mode) {
    'analyze', 'analyze-with-config' => $engine->analyzeAction($ref)->toArray(),
    'analyze-callable' => $engine->analyzeCallable(new CallableRef(
        $file,
        $class === '' ? null : $class,
        $method === '' ? null : $method,
        $line,
        $narrowParam,
        $narrowType,
    ))->toArray(),
    // Two callables through one engine (shared per-callee memo) under the tiny bounds: the determinism
    // guard for the refiner's "only serve a memo entry a caller could have earned" rule.
    'refine-pair' => (static function () use ($engine, $app, $argv): array {
        $analyse = static fn (string $relPath, string $class, string $method): array => $engine->analyzeCallable(
            new CallableRef($app.'/'.$relPath, $class === '' ? null : $class, $method === '' ? null : $method),
        )->toArray();

        return [
            'first' => $analyse($argv[4] ?? '', $argv[5] ?? '', $argv[6] ?? ''),
            'second' => $analyse($argv[7] ?? '', $argv[8] ?? '', $argv[9] ?? ''),
        ];
    })(),
    'class-metadata' => $engine->classMetadata(new ClassRef($class))->toArray(),
    'trace-qb' => $qbHarvest(),
    // The replay layer's real-path parity: analyse the action first, so the controller's walk is recorded
    // by the METHOD harvest, then trace it twice off that recording. Every harvest here — and the one
    // trace-qb takes off a live pass in its own subprocess — must be the same harvest.
    'trace-qb-replay' => (static function () use ($engine, $ref, $qbHarvest, &$countedAdapters): array {
        $analysis = $engine->analyzeAction($ref);
        $first = $qbHarvest();
        $second = $qbHarvest();

        // How many live walks the action's own file cost across all three asks. One recorder shared by the
        // harvest and both traces makes it 1; a recorder per consumer makes it more.
        $adapter = $countedAdapters[0] ?? null;
        $passes = $adapter?->passes[$adapter->normalize($ref->file)] ?? null;

        return [
            'returns' => count($analysis->returns),
            'passes' => $passes,
            'first' => $first,
            'second' => $second,
        ];
    })(),
    'trace-qb-enrich' => (static function () use ($engine, $ref): array {
        // The real trace visitor plus the real cast-recovery resolver, inside the host app's process
        // where its models/enums are autoloadable: an enum-cast column recovers to its emitted
        // enum-filter shape (backing values + case descriptions) end-to-end.
        $visitor = new QueryBuilderTraceVisitor;
        $report = $engine->trace($ref, $visitor);
        $dependencyFiles = $report->dependencyFiles;

        // What the extension does for an action that is handed its builder: one more root per injected
        // QueryBuilder subclass (its constructor holds the allow-lists), same visitor, action first, and
        // every walk's files kept — those are what the extension records for the fragment cache.
        foreach (QbBuilderRoots::forAction($ref) as $root) {
            $dependencyFiles = [...$dependencyFiles, ...$engine->trace($root, $visitor)->dependencyFiles];
        }

        $facts = $visitor->facts;

        $resolver = new FilterColumnResolver;
        $scopes = new ScopeParameterResolver;
        $filters = array_map(static function (QbEntry $filter) use ($resolver, $scopes, $facts): array {
            $model = $facts->subjectModel;
            // Mirror the extension's per-kind typing: a resolved column (exact/callback) off the model
            // cast, a scope value parameter off its scope method — both against the real engine.
            $column = match (true) {
                // A project-factory filter carrying a backed-enum class-string types off it directly,
                // no model needed — the ListFilters-style recovery.
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
                // A custom filter's class, which for the instance form (`new F`) only the typed `new`
                // expression at the call site can name.
                'filterClass' => $filter->filterClass,
                // The column the value types off — recovered from a factory argument, or from the AST of a
                // callback closure the engine folded out of a helper's body.
                'typeColumn' => $filter->typeColumn,
                'nullable' => $filter->nullable,
                // The recovered leading comment — PHPStan's parser attributes it to the array item the
                // same way ParserFactory does, which is what makes it usable as an override description.
                'comment' => $filter->comment,
                'columnKind' => $column?->kind,
                'enum' => $column?->enum,
                'values' => $column?->enum !== null ? EnumReflection::values($column->enum) : [],
                'descriptions' => $column?->enum !== null ? EnumReflection::descriptions($column->enum) : [],
                'dependencyBasenames' => array_map('basename', $column?->dependencyFiles ?? []),
                'scalarSchema' => $column?->scalarSchema,
            ];
        }, $facts->filters);

        // visitedBasenames is the cache-soundness check: a Query class reached only via the return-type
        // follow-beyond still lands in the trace's dependency files, so editing it invalidates.
        return [
            'subjectModel' => $facts->subjectModel,
            'filters' => $filters,
            'sorts' => array_map(static fn (QbEntry $s): string => $s->name, $facts->sorts),
            'sortKinds' => array_map(static fn (QbEntry $s): string => $s->kind, $facts->sorts),
            'includes' => array_map(static fn (QbEntry $i): string => $i->name, $facts->includes),
            'defaultSorts' => $facts->defaultSorts,
            // The degradation half: an entry the engine could not fold is a named diagnostic, so a test can
            // pin recovery AND the absence of it.
            'unresolved' => $facts->unresolved,
            'paginates' => $facts->paginates,
            'paginationTerminal' => $facts->paginationTerminal,
            // The page-size key the trace followed the request into a callee to find, and the default that
            // read was written with — both null for a chain whose size is fixed at the call site.
            'pageSizeKey' => $facts->pageSize?->key,
            'pageSizeDefault' => $facts->pageSize?->default,
            'visitedBasenames' => array_map('basename', $dependencyFiles),
        ];
    })(),
    'trace-rules' => (static function () use ($engine, $ref): array {
        // RulesMethodVisitor recovers a rules() method's returned array with AST-level constant folding
        // so Rule::enum(...) descriptors survive. Returns each field's rule names + params, the fields
        // that are present but unrecoverable, and the ones that recovered minus a constraint they widened.
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

        return [
            'fields' => $fields,
            'unrecoverable' => $visitor->unrecoverableFields(),
            'widened' => $visitor->widenedFields(),
        ];
    })(),
    'trace-inline-rules' => (static function () use ($engine, $ref): array {
        // InlineRulesVisitor traces the controller action, so the engine's bounded descent has to reach
        // a `Validator::make($data, [...])` call inside a Queries class one hop away and recover its
        // rule array — the modular GET-params validation pattern.
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

        return [
            'fields' => $fields,
            'unrecoverable' => $visitor->unrecoverableFields(),
            'widened' => $visitor->widenedFields(),
        ];
    })(),
    'trace-json-api-paginate' => (static function () use ($engine, $ref): array {
        // The shared PaginationTerminalVisitor recovers the jsonPaginate terminal + its outermost
        // int args (jsonPaginate(?maxResults, ?defaultSize)) on the real engine.
        $visitor = new PaginationTerminalVisitor(['jsonPaginate' => 'length']);
        $engine->trace($ref, $visitor);

        return [
            'paginates' => $visitor->paginates,
            'maxResults' => $visitor->intArg(0) ?? $visitor->intArg('maxResults'),
            'defaultSize' => $visitor->intArg(1) ?? $visitor->intArg('defaultSize'),
        ];
    })(),
    'trace-pagination-terminal' => (static function () use ($engine, $ref): array {
        // The shared visitor detects paginate/simplePaginate/cursorPaginate on a real builder receiver
        // at chain depth.
        $visitor = new PaginationTerminalVisitor([
            'paginate' => 'length',
            'simplePaginate' => 'simple',
            'cursorPaginate' => 'cursor',
        ]);
        $engine->trace($ref, $visitor);

        // `pageName` is the third argument of all three signatures — the key the endpoint really reads.
        // `pageSizeKey` is the other half: the key the SIZE argument was followed back to a request read
        // for, which is a call-graph fact rather than an argument of this call.
        return [
            'paginates' => $visitor->paginates,
            'kind' => $visitor->kind,
            'terminal' => $visitor->terminal,
            'pageName' => $visitor->stringArg(2) ?? $visitor->stringArg('pageName'),
            'pageSizeKey' => $visitor->pageSize()?->key,
            'pageSizeDefault' => $visitor->pageSize()?->default,
        ];
    })(),
    'trace-closure' => (static function () use ($engine, $file, $line): array {
        // The closure-by-line trace — how a closure route's action is walked — hands each return
        // expression to the visitor with a live scope, for both the arrow-function and full-closure
        // shapes.
        $probe = new ClosureReturnProbe;
        $engine->trace(new ActionRef($file, null, '{closure}', $line), $probe);

        return ['returns' => $probe->returns];
    })(),
    'trace-file-responses' => (static function () use ($engine, $ref): array {
        // FileResponseVisitor reads what a download/stream/event-stream call proves — the half the
        // return type cannot carry.
        $visitor = new FileResponseVisitor;
        $engine->trace($ref, $visitor);

        return ['calls' => array_map(static fn (FileResponseCall $call): array => [
            'responseClass' => $call->responseClass,
            'mediaType' => $call->mediaType,
            'schema' => $call->schema,
            'disposition' => $call->disposition,
            'filename' => $call->filename,
        ], $visitor->calls)];
    })(),
    'trace-created-resource' => (static function () use ($engine, $ref): array {
        // CreatedResourceVisitor recognises a resource wrapping a real Model::create() — the 201 status
        // recovery.
        $visitor = new CreatedResourceVisitor;
        $engine->trace($ref, $visitor);

        return ['created' => $visitor->created];
    })(),
    default => ['error' => 'unknown mode: '.$mode],
};

fwrite(STDOUT, "\n@@RESULT@@".json_encode($result, JSON_THROW_ON_ERROR)."\n");
