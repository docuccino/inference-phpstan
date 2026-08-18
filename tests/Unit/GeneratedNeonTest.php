<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Runtime\BootFailedException;
use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Runtime\V2_2\RuntimeAdapter;

/*
 * The config file the adapter writes for itself before booting the container. Booting is the fixture
 * suite's job; what is proven here is the part a real install breaks on and a fixture never would —
 * a path with a space or an apostrophe in it, and a tmp dir that cannot be written.
 */

it('quotes every path it interpolates, so a space or an apostrophe cannot break the config', function (): void {
    $tmpDir = sys_get_temp_dir()."/docuccino neon test-o'brien";
    @mkdir($tmpDir, 0o777, true);
    $userNeon = $tmpDir.'/user config.neon';
    file_put_contents($userNeon, "parameters:\n");

    $adapter = new RuntimeAdapter(new RuntimeConfig(
        projectRoot: $tmpDir,
        tmpDir: $tmpDir,
        phpVersion: PHP_VERSION_ID,
        projectPaths: [$tmpDir.'/app'],
        userNeon: $userNeon,
    ));

    $write = new ReflectionMethod($adapter, 'writeGeneratedNeon');
    $generated = $write->invoke($adapter, '/opt/my vendor/larastan/larastan/extension.neon');
    $neon = (string) file_get_contents((string) $generated);

    // NEON's literal string: single quotes, and the one escape is the doubled apostrophe.
    expect($neon)
        ->toContain("    - '/opt/my vendor/larastan/larastan/extension.neon'")
        ->toContain("    - '".str_replace("'", "''", $userNeon)."'")
        ->toContain("    tmpDir: '".str_replace("'", "''", $tmpDir)."'")
        // Every bundled stub is a path too, and the engine ships at least one.
        ->toContain("        - '")
        // The apostrophe really is doubled rather than merely wrapped.
        ->toContain("test-o''brien")
        // phpVersion is an int and stays one — quoting it would hand PHPStan a string.
        ->toContain('    phpVersion: '.PHP_VERSION_ID);

    array_map('unlink', [$userNeon, (string) $generated]);
    rmdir($tmpDir);
});

it('fails the boot, with the path, when the generated config cannot be written', function (): void {
    // An unwritable tmp dir used to be a silent no-op that surfaced later as an unattributable
    // container error; the engine reports it as the boot failure it is (`engine.boot-failed`).
    $missing = sys_get_temp_dir().'/docuccino-neon-absent-'.getmypid().'/nested';

    $adapter = new RuntimeAdapter(new RuntimeConfig(
        projectRoot: $missing,
        tmpDir: $missing,
        phpVersion: PHP_VERSION_ID,
        projectPaths: [$missing.'/app'],
    ));

    $write = new ReflectionMethod($adapter, 'writeGeneratedNeon');

    expect(fn () => $write->invoke($adapter, $missing.'/vendor/larastan/larastan/extension.neon'))
        ->toThrow(BootFailedException::class, $missing.'/docuccino.neon');
});
