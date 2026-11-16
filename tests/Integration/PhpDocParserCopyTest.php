<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Docuccino\Core\TypeGrammar\PhpDocParserStack;
use Docuccino\Inference\PhpStan\Runtime\PhpDocParserCopy;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use PHPStan\PhpDocParser\Lexer\Lexer;

/**
 * One phpstan/phpdoc-parser per process. Core reads docblocks with the application's copy, the PHPStan
 * phar carries its own, and the phar's autoloader, once registered, answers the same class names ahead of
 * the application's. A build reads route docblocks before the engine boots, so without a settled copy a
 * parser class core already loaded meets a token iterator from the phar — and where the two versions
 * differ, every action's inference fails.
 */

/** @return array{parsed: bool, copies: list<string>} */
function phpDocParserCopyRun(string $order, string $complete): array
{
    $script = dirname(__DIR__).'/bin/phpdoc-parser-copy.php';
    $output = (string) shell_exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'pcov.enabled=0', $script, $order, $complete])).' 2>&1');
    $marker = strrpos($output, '@@RESULT@@');
    if ($marker === false) {
        throw new RuntimeException("phpdoc-parser-copy.php produced no result:\n".$output);
    }

    /** @var array{parsed: bool, copies: list<string>} */
    return json_decode(trim(substr($output, $marker + strlen('@@RESULT@@'))), true, flags: JSON_THROW_ON_ERROR);
}

it('mixes the two copies when core reads a docblock before PHPStan loads its first scoped class', function (): void {
    // The mechanism the fix answers, executed: without it, the classes core loaded come from the
    // application's copy and every one after the phar's autoloader registered comes from the phar's.
    expect(phpDocParserCopyRun('core-first', 'leave')['copies'])->toBe(['phar', 'vendor']);
});

it('reads one copy, whichever loaded first, once the engine settles it before booting', function (string $order, array $copies): void {
    // Core first: the application's copy is already in play, so the rest of it is loaded and the phar's
    // never answers. Engine first: nothing is in play yet, so the choice is left to PHPStan, which serves
    // every class from its own copy, core's included. Either way a generic type parses — which is the
    // call that fails when a newer parser meets an older token iterator.
    $run = phpDocParserCopyRun($order, 'complete');

    expect($run['copies'])->toBe($copies)
        ->and($run['parsed'])->toBeTrue();
})->with([
    'core first' => ['core-first', ['vendor']],
    'engine first' => ['engine-first', ['phar']],
]);

it('leaves every class of the application copy loaded once one is', function (): void {
    // Core's parser stack puts the application's copy in play, as a route's docblock does in a build.
    new PhpDocParserStack;
    $directory = dirname((string) realpath((string) (new ReflectionClass(Lexer::class))->getFileName()), 2);
    if (str_starts_with($directory, 'phar://') || ! in_array($directory, array_map(
        static fn (string $path): string|false => realpath($path),
        array_merge(...array_map(
            static fn (ClassLoader $loader): array => $loader->getPrefixesPsr4()['PHPStan\\PhpDocParser\\'] ?? [],
            array_values(ClassLoader::getRegisteredLoaders()),
        )),
    ), true)) {
        $this->markTestSkipped('This worker loaded the phar copy first, so the application copy is not in play.');
    }

    PhpDocParserCopy::complete();

    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        $class = 'PHPStan\\PhpDocParser\\'.str_replace(DIRECTORY_SEPARATOR, '\\', substr($file->getPathname(), strlen((string) $directory) + 1, -4));
        if (! class_exists($class, false) && ! interface_exists($class, false) && ! trait_exists($class, false)) {
            $missing[] = $class;
        }
    }

    // A scan that saw nothing would pass the assertion below forever.
    expect(iterator_count($files))->toBeGreaterThan(50)
        ->and($missing)->toBe([]);
});

it('analyses an action with one copy when core read a docblock before the engine booted', function (): void {
    // The real boot, in the order a build runs it: the route's docblock first, then the engine. The
    // classes core loaded are the application's, so the engine loads the rest of that copy before PHPStan
    // registers the phar's autoloader, and the analysis that follows reads that one copy throughout.
    ensureFixtureAvailable(FixtureRunner::available());

    $result = FixtureRunner::analyzeCoreFirst(
        'app/Http/Controllers/SpikeController.php',
        'App\\Http\\Controllers\\SpikeController',
        'listUsers',
    );

    expect($result['phpDocParserCopies'])->toBe(['vendor'])
        ->and($result['diagnostics'])->toBe([])
        ->and($result['returns'][0]['type']['fqcn'])->toBe('Illuminate\\Database\\Eloquent\\Collection');
})->group('fixture');
