<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\ProjectFilter;

/**
 * The containment gate every descent asks before it opens a file. Its negative answers are the product:
 * one wrong `true` follows the trace into vendor code, where a recovered "fact" is the framework's rather
 * than the application's.
 */
function projectFilter(array $paths): ProjectFilter
{
    return new ProjectFilter($paths, static fn (string $file): string => $file);
}

it('admits a file under one of the project paths', function (string $file): void {
    expect(projectFilter(['/app', '/modules/'])->isProjectFile($file))->toBeTrue();
})->with([
    'the path itself' => ['/app'],
    'a file directly inside it' => ['/app/User.php'],
    'a file nested inside it' => ['/app/Http/Controllers/UserController.php'],
    // A trailing slash on the configured path is the same path.
    'a file under a path written with a trailing slash' => ['/modules/Billing/Query.php'],
]);

it('declines everything else', function (?string $file): void {
    expect(projectFilter(['/app'])->isProjectFile($file))->toBeFalse();
})->with([
    'a vendor file' => ['/vendor/laravel/framework/src/Model.php'],
    // The prefix has to end at a directory boundary, or `/app-extra` rides in on `/app`.
    'a sibling directory sharing the prefix' => ['/app-extra/User.php'],
    'a file above it' => ['/User.php'],
    // A PHP-internal or evaluated method has no file at all, and the engine asks anyway.
    'no file' => [null],
    'an empty path' => [''],
]);

it('declines every file when no project path is configured', function (): void {
    expect(projectFilter([])->isProjectFile('/app/User.php'))->toBeFalse();
});
