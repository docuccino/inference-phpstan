<?php

declare(strict_types=1);

/*
 * The `contract-builds` mode of the engine runner: the adapter's contract assertions run in ONE process over
 * several documents, in order, as a test suite holding each of its documents to the committed artifact runs
 * them. The fixture app boots with docuccino/laravel discovered, as an installed application has it, so every
 * application the process boots registers it — and the analyser boots one of its own. The documents and
 * their routes come from a provider for the same reason: an application's route files are read by every
 * application it boots.
 */

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Testing\ApiContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Yaml\Yaml;

/** Two documents, each over the same controller's actions under a prefix of its own. */
final class ContractBuildsProvider extends ServiceProvider
{
    public const DOCUMENTS = ['first', 'second'];

    public const ACTIONS = ['json', 'constructed', 'helperStatus', 'constant'];

    public function register(): void
    {
        config(['cache.default' => 'array']);

        $documents = [];
        foreach (self::DOCUMENTS as $key) {
            $documents[$key] = ['routes' => ['include' => ['api/'.$key.'/*']]];
        }

        $this->app->instance(BuildConfig::class, new BuildConfig(ConfigFile::parse(Yaml::dump(['documents' => $documents], 8))));
    }

    public function boot(): void
    {
        $router = $this->app->make('router');
        foreach (self::DOCUMENTS as $key) {
            foreach (self::ACTIONS as $action) {
                $router->get('api/'.$key.'/'.$action, ['App\\Http\\Controllers\\CaseStatusResponseController', $action]);
            }
        }
    }
}

/**
 * Each document built in turn through `ApiContract::build()`, as `assertDocumentUpToDate()` builds it: every
 * path's `GET` response statuses, beside the paths and components themselves. With `$perTest`, each is built in an application booted for it, as each
 * test of a suite gets one; without, all of them are built in one, as one test building several does.
 *
 * @param  list<string>  $keys
 * @return array<string, array{statuses: array<string, list<string>>, paths: mixed, components: mixed}>
 */
function contractBuilds(string $app, string $tmp, array $keys, bool $perTest): array
{
    // A storage of the run's own, so the engine's generated container is this run's alone.
    foreach (['app', 'framework/cache', 'framework/views', 'framework/sessions', 'logs'] as $directory) {
        @mkdir($tmp.'/storage/'.$directory, 0777, true);
    }

    $packages = require $app.'/bootstrap/cache/packages.php';
    $packages['docuccino/laravel'] = ['providers' => ['Docuccino\\Laravel\\DocuccinoServiceProvider']];
    $packages['docuccino/contract-builds'] = ['providers' => [ContractBuildsProvider::class]];
    file_put_contents($tmp.'/packages.php', '<?php return '.var_export($packages, true).';');

    foreach (['APP_PACKAGES_CACHE' => $tmp.'/packages.php', 'APP_SERVICES_CACHE' => $tmp.'/services.php', 'LARAVEL_STORAGE_PATH' => $tmp.'/storage'] as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    $boot = static function () use ($app): void {
        $laravel = require $app.'/bootstrap/app.php';
        $laravel->make(Kernel::class)->bootstrap();
    };
    $boot();

    $built = [];
    foreach ($keys as $index => $key) {
        if ($perTest && $index > 0) {
            $boot();
        }

        ApiContract::forDocument($key);
        $document = ApiContract::build()->fresh()->toArray();

        $statuses = [];
        foreach ($document['paths'] ?? [] as $path => $item) {
            $responses = is_array($item) && is_array($item['get'] ?? null) && is_array($item['get']['responses'] ?? null) ? $item['get']['responses'] : [];
            $statuses[(string) $path] = array_map('strval', array_keys($responses));
        }
        ksort($statuses);

        $built[$key] = ['statuses' => $statuses, 'paths' => $document['paths'] ?? [], 'components' => $document['components'] ?? []];
    }

    return $built;
}
