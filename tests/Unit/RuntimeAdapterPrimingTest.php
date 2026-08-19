<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Runtime\RuntimeConfig;
use Docuccino\Inference\PhpStan\Runtime\V2_2\RuntimeAdapter;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\File\FileHelper;
use PHPStan\Parser\PathRoutingParser;

/**
 * In-process cover for the adapter's priming. Booting the real container is the fixture suite's job;
 * both PHPStan services take part only through `setAnalysedFiles()`, which in each is a plain
 * `$this->analysedFiles = array_fill_keys($files, true)`, so a constructor-less instance is a faithful
 * stand-in and the set each ends up holding is the whole observable behaviour.
 */

/**
 * A booted-looking adapter with a real FileHelper and both services stubbed in.
 *
 * @return array{RuntimeAdapter, PathRoutingParser, NodeScopeResolver}
 */
function primingAdapterUnderTest(): array
{
    $adapter = new RuntimeAdapter(new RuntimeConfig(
        projectRoot: '/app',
        tmpDir: '/app/tmp',
        phpVersion: PHP_VERSION_ID,
        projectPaths: ['/app/app'],
    ));

    $parser = (new ReflectionClass(PathRoutingParser::class))->newInstanceWithoutConstructor();
    $resolver = (new ReflectionClass(NodeScopeResolver::class))->newInstanceWithoutConstructor();

    foreach (['fileHelper' => new FileHelper('/app'), 'pathRoutingParser' => $parser, 'nodeScopeResolver' => $resolver] as $property => $service) {
        (new ReflectionProperty($adapter, $property))->setValue($adapter, $service);
    }

    return [$adapter, $parser, $resolver];
}

/**
 * @return list<string>
 */
function analysedFileSet(PathRoutingParser|NodeScopeResolver $service): array
{
    /** @var array<string, true> $set */
    $set = (new ReflectionProperty($service, 'analysedFiles'))->getValue($service);

    return array_keys($set);
}

it('primes both services with the whole set, sorted, whenever a file joins it', function (): void {
    // Grow-only and insertion-order-independent: a file already primed is never dropped, and the list
    // handed over is sorted, so the same project always primes byte-identically.
    [$adapter, $parser, $resolver] = primingAdapterUnderTest();

    $adapter->prime(['/app/app/B.php']);
    $adapter->prime(['/app/app/A.php']);

    expect(analysedFileSet($parser))->toBe(['/app/app/A.php', '/app/app/B.php'])
        ->and(analysedFileSet($resolver))->toBe(['/app/app/A.php', '/app/app/B.php']);
});

it('re-primes for a batch that is only partly new', function (): void {
    // The skip is decided per batch, not per file: one unknown file in the batch must still push the
    // full set, or a descent target gets parsed by CleaningParser with its method bodies deleted.
    [$adapter, $parser, $resolver] = primingAdapterUnderTest();

    $adapter->prime(['/app/app/A.php']);
    $adapter->prime(['/app/app/A.php', '/app/app/B.php']);

    expect(analysedFileSet($parser))->toBe(['/app/app/A.php', '/app/app/B.php'])
        ->and(analysedFileSet($resolver))->toBe(['/app/app/A.php', '/app/app/B.php']);
});

it('leaves both services alone when every file in the batch is already primed', function (): void {
    // processFile() primes per file and boot() has already primed the project, so this is the hot
    // path. A sentinel only a fresh setAnalysedFiles() call could erase proves the call is skipped.
    [$adapter, $parser, $resolver] = primingAdapterUnderTest();

    $adapter->prime(['/app/app/A.php', '/app/app/B.php']);

    $parser->setAnalysedFiles(['sentinel']);
    $resolver->setAnalysedFiles(['sentinel']);

    $adapter->prime(['/app/app/A.php']);
    $adapter->prime(['/app/app/B.php', '/app/app/A.php']);
    $adapter->prime([]);

    expect(analysedFileSet($parser))->toBe(['sentinel'])
        ->and(analysedFileSet($resolver))->toBe(['sentinel']);
});

it('reports the analysed set size, counting each normalised file once', function (): void {
    // What FileWalks stamps a recording with: the set is grow-only, so its SIZE identifies it, and a
    // re-primed or non-canonically spelled file must not look like growth or every recording goes stale.
    [$adapter] = primingAdapterUnderTest();

    expect($adapter->analysedFileCount())->toBe(0);

    $adapter->prime(['/app/app/A.php', '/app/app/B.php']);
    expect($adapter->analysedFileCount())->toBe(2);

    $adapter->prime(['/app/app/A.php', '/app/app/Sub/../B.php']);
    expect($adapter->analysedFileCount())->toBe(2);

    $adapter->prime(['/app/app/C.php']);
    expect($adapter->analysedFileCount())->toBe(3);
});

it('recognises an already-primed file spelled non-canonically', function (): void {
    // Membership is tested on the normalised path, the same form the set is keyed by — otherwise a
    // caller's relative-segment spelling would re-prime, and re-add a duplicate of an existing entry.
    [$adapter, $parser, $resolver] = primingAdapterUnderTest();

    $adapter->prime(['/app/app/A.php']);

    $parser->setAnalysedFiles(['sentinel']);
    $resolver->setAnalysedFiles(['sentinel']);

    $adapter->prime(['/app/app/Sub/../A.php']);

    expect(analysedFileSet($parser))->toBe(['sentinel'])
        ->and(analysedFileSet($resolver))->toBe(['sentinel']);
});
