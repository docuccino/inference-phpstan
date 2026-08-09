<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Inference\PhpStan\Cache\VersionFingerprint;
use Docuccino\Inference\PhpStan\Runtime\EngineVersion;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;

function fakeProject(string $lockJson, string $larastanNeon = 'params: []'): string
{
    $root = sys_get_temp_dir().'/docuccino-fp-'.bin2hex(random_bytes(6));
    mkdir($root.'/vendor/larastan/larastan', 0o777, true);
    file_put_contents($root.'/composer.lock', $lockJson);
    file_put_contents($root.'/vendor/larastan/larastan/extension.neon', $larastanNeon);

    return $root;
}

function runtimeFor(string $root): RuntimeConfig
{
    return new RuntimeConfig($root, $root.'/tmp', 80300, [$root.'/app']);
}

it('reads phpstan and larastan versions out of the app composer.lock', function (): void {
    $lock = json_encode([
        'packages-dev' => [
            ['name' => 'phpstan/phpstan', 'version' => '2.2.5'],
            ['name' => 'larastan/larastan', 'version' => '3.10.0'],
        ],
    ], JSON_THROW_ON_ERROR);

    $root = fakeProject($lock);
    $fp = VersionFingerprint::forRuntime(runtimeFor($root));

    expect($fp->engineVersion)->toBe(EngineVersion::ID)
        ->and($fp->phpstanVersion)->toBe('2.2.5')
        ->and($fp->larastanVersion)->toBe('3.10.0')
        ->and($fp->composerLockHash)->toBe(hash('sha256', $lock));

    exec('rm -rf '.escapeshellarg($root));
});

it('changes its prefix when the composer.lock changes', function (): void {
    $rootA = fakeProject(json_encode(['packages' => [['name' => 'phpstan/phpstan', 'version' => '2.2.5']]], JSON_THROW_ON_ERROR));
    $rootB = fakeProject(json_encode(['packages' => [['name' => 'phpstan/phpstan', 'version' => '2.3.0']]], JSON_THROW_ON_ERROR));

    $a = VersionFingerprint::forRuntime(runtimeFor($rootA));
    $b = VersionFingerprint::forRuntime(runtimeFor($rootB));

    expect($a->prefix())->not->toBe($b->prefix());

    exec('rm -rf '.escapeshellarg($rootA));
    exec('rm -rf '.escapeshellarg($rootB));
});

it('is stable across repeated computation for the same inputs', function (): void {
    $root = fakeProject(json_encode(['packages' => []], JSON_THROW_ON_ERROR));

    $first = VersionFingerprint::forRuntime(runtimeFor($root));
    $second = VersionFingerprint::forRuntime(runtimeFor($root));

    expect($first->prefix())->toBe($second->prefix());

    exec('rm -rf '.escapeshellarg($root));
});

it('folds the neon hash into the prefix', function (): void {
    $lock = json_encode(['packages' => []], JSON_THROW_ON_ERROR);
    $rootA = fakeProject($lock, 'params: []');
    $rootB = fakeProject($lock, 'params: [level: max]');

    $a = VersionFingerprint::forRuntime(runtimeFor($rootA));
    $b = VersionFingerprint::forRuntime(runtimeFor($rootB));

    expect($a->neonHash)->not->toBe($b->neonHash)
        ->and($a->prefix())->not->toBe($b->prefix());

    exec('rm -rf '.escapeshellarg($rootA));
    exec('rm -rf '.escapeshellarg($rootB));
});
