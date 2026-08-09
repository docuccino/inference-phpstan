<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Inference\PhpStan\Cache\FilesystemEngineResultCache;
use Docuccino\Inference\PhpStan\Cache\NullEngineResultCache;
use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;

function cacheDir(): string
{
    $dir = sys_get_temp_dir().'/docuccino-cache-unit-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    return $dir;
}

function fingerprint(): VersionFingerprint
{
    return new VersionFingerprint('e1', 'p2.2.5', 'l3.10', 'neon-hash', 'lock-hash');
}

function sampleAnalysis(string $actionFile, string $depFile): ActionAnalysis
{
    return new ActionAnalysis(
        returns: [new ReturnSite(new ScalarT('string'), new SourceLocation($actionFile, 10))],
        throws: [],
        diagnostics: [new Diagnostic(Severity::Hint, 'x.y', 'note')],
        dependencyFiles: [$actionFile, $depFile],
    );
}

it('misses on an empty cache and hits after a store, byte-identically', function (): void {
    $dir = cacheDir();
    $actionFile = $dir.'/Action.php';
    $depFile = $dir.'/Dep.php';
    file_put_contents($actionFile, '<?php // action');
    file_put_contents($depFile, '<?php // dep');

    $cache = new FilesystemEngineResultCache($dir);
    $ref = new ActionRef($actionFile, 'App\\Action', 'handle', 10);
    $fp = fingerprint();
    $analysis = sampleAnalysis($actionFile, $depFile);

    expect($cache->getAction($ref, $fp))->toBeNull();

    $cache->putAction($ref, $analysis, $fp);
    $hit = $cache->getAction($ref, $fp);

    expect($hit)->not->toBeNull()
        ->and(json_encode($hit->toArray()))->toBe(json_encode($analysis->toArray()));

    exec('rm -rf '.escapeshellarg($dir));
});

it('invalidates the entry when a dependency file three calls deep changes', function (): void {
    $dir = cacheDir();
    $actionFile = $dir.'/Action.php';
    $depFile = $dir.'/Dep.php';
    file_put_contents($actionFile, '<?php // action');
    file_put_contents($depFile, '<?php // dep v1');

    $cache = new FilesystemEngineResultCache($dir);
    $ref = new ActionRef($actionFile, 'App\\Action', 'handle', 10);
    $fp = fingerprint();

    $cache->putAction($ref, sampleAnalysis($actionFile, $depFile), $fp);
    expect($cache->getAction($ref, $fp))->not->toBeNull();

    // A change in the depfile (not the action file itself) must invalidate.
    file_put_contents($depFile, '<?php // dep v2 changed');
    expect($cache->getAction($ref, $fp))->toBeNull();

    exec('rm -rf '.escapeshellarg($dir));
});

it('invalidates on a fingerprint (tool version) change', function (): void {
    $dir = cacheDir();
    $actionFile = $dir.'/Action.php';
    file_put_contents($actionFile, '<?php // action');

    $cache = new FilesystemEngineResultCache($dir);
    $ref = new ActionRef($actionFile, 'App\\Action', 'handle', 10);

    $cache->putAction($ref, sampleAnalysis($actionFile, $actionFile), fingerprint());

    $bumped = new VersionFingerprint('e2', 'p2.2.5', 'l3.10', 'neon-hash', 'lock-hash');
    expect($cache->getAction($ref, $bumped))->toBeNull();

    exec('rm -rf '.escapeshellarg($dir));
});

it('caches class metadata keyed on the class file', function (): void {
    $dir = cacheDir();
    $classFile = $dir.'/User.php';
    file_put_contents($classFile, '<?php // user v1');

    $cache = new FilesystemEngineResultCache($dir);
    $ref = new ClassRef('App\\Models\\User', $classFile);
    $fp = fingerprint();
    $meta = new ClassMetadata('App\\Models\\User', [new PropertyMetadata('id', new ScalarT('int'))], 'A user');

    expect($cache->getClass($ref, $fp))->toBeNull();
    $cache->putClass($ref, $meta, $fp);
    expect(json_encode($cache->getClass($ref, $fp)?->toArray()))->toBe(json_encode($meta->toArray()));

    file_put_contents($classFile, '<?php // user v2');
    expect($cache->getClass($ref, $fp))->toBeNull();

    exec('rm -rf '.escapeshellarg($dir));
});

it('tolerates a corrupt cache file as a miss', function (): void {
    $dir = cacheDir();
    $actionFile = $dir.'/Action.php';
    file_put_contents($actionFile, '<?php // action');
    $cache = new FilesystemEngineResultCache($dir);
    $ref = new ActionRef($actionFile, 'App\\Action', 'handle', 10);
    $fp = fingerprint();

    $cache->putAction($ref, sampleAnalysis($actionFile, $actionFile), $fp);
    $file = (glob($dir.'/actions/*.json') ?: [])[0] ?? null;
    expect($file)->not->toBeNull();
    file_put_contents((string) $file, '{not valid json');

    expect($cache->getAction($ref, $fp))->toBeNull();

    exec('rm -rf '.escapeshellarg($dir));
});

it('is a total no-op through the null cache', function (): void {
    $cache = new NullEngineResultCache;
    $ref = new ActionRef('/x.php', 'App\\X', 'handle', 1);
    $fp = fingerprint();

    $cache->putAction($ref, sampleAnalysis('/x.php', '/x.php'), $fp);
    expect($cache->getAction($ref, $fp))->toBeNull();
    expect($cache->getClass(new ClassRef('App\\X'), $fp))->toBeNull();
});
