<?php

declare(strict_types=1);

/*
 * Which phpdoc-parser copy a process ends up reading, in a process of its own (the Pest worker already has
 * both libraries loaded in whatever order its earlier tests left them).
 *
 * Usage: php phpdoc-parser-copy.php <core-first|engine-first> <complete|leave>
 *
 * `core-first` builds core's parser stack before PHPStan loads its first scoped class, as an adapter that
 * reads a docblock before the engine boots does; `engine-first` builds it after. `complete` runs
 * {@see PhpDocParserCopy::complete()} where the engine runs it, just before PHPStan boots.
 *
 * Prints `@@RESULT@@` and one JSON line: whether a generic type parsed, and which copies the loaded classes
 * came from. Anything an older dependency prints while loading comes before the marker.
 */

use Docuccino\Core\TypeGrammar\PhpDocParserStack;
use Docuccino\Inference\PhpStan\Runtime\PhpDocParserCopy;

require dirname(__DIR__, 4).'/vendor/autoload.php';

[, $order, $complete] = $argv + [null, 'core-first', 'leave'];

$stack = $order === 'core-first' ? new PhpDocParserStack : null;

if ($complete === 'complete') {
    PhpDocParserCopy::complete();
}

// PHPStan's own trigger: the first `_PHPStan_` class it is asked for registers the phar's autoloader.
class_exists('_PHPStan_\\Probe');

$stack ??= new PhpDocParserStack;
$parsed = $stack->parseType('Collection<User>') !== null;

$copies = [];
foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
    if (str_starts_with($class, 'PHPStan\\PhpDocParser\\')) {
        $copies[str_starts_with((string) (new ReflectionClass($class))->getFileName(), 'phar://') ? 'phar' : 'vendor'] = true;
    }
}
ksort($copies);

echo '@@RESULT@@', json_encode(['parsed' => $parsed, 'copies' => array_keys($copies)]), "\n";
