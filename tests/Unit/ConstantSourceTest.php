<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\ProbeConstantHolder;
use Docuccino\Inference\PhpStan\Throwing\ConstantSource;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The second file a folded status was written in. A status spelled as a class constant is decided
 * somewhere the body that names it is not, so a fragment naming only the body stays warm when the
 * declaration changes — and the route publishes the old number with nothing stale to notice.
 *
 * Every row states which file PHP would take the constant FROM, not which one a reader finds convenient:
 * a constant is edited where it is declared, which for an inherited or interface constant is neither the
 * class written at the call nor the class the line sits in.
 */
function constantExpr(string $code): Node\Expr
{
    $parsed = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code.';') ?? [];
    $statement = (new NodeFinder)->findFirstInstanceOf($parsed, Node\Stmt\Expression::class);

    expect($statement)->not->toBeNull();

    /** @var Node\Stmt\Expression $statement */
    return $statement->expr;
}

/**
 * @return list<string>
 */
function constantFiles(string $code, ?string $self = ProbeConstantHolder::class): array
{
    return array_map(
        static fn (string $file): string => basename($file),
        ConstantSource::files(constantExpr($code), $self),
    );
}

it('names the file every class constant an expression reaches is declared in', function (string $code, array $files): void {
    expect(constantFiles($code))->toBe($files);
})->with([
    // Written by name, which is the commonest spelling and the one whose file the body never mentions.
    'a constant on another class' => [
        '\Docuccino\Inference\PhpStan\Tests\Support\Fixtures\ProbeConstantHolder::OWN',
        ['ProbeConstantHolder.php'],
    ],
    // `self` is the class the LINE is written in, which is what the caller passes.
    'self' => ['self::OWN', ['ProbeConstantHolder.php']],
    'static' => ['static::OWN', ['ProbeConstantHolder.php']],
    // Inherited: PHP takes the value from where it is DECLARED, so that is the file that has to invalidate.
    'a constant inherited from a base' => ['self::FROM_BASE', ['ProbeConstantBase.php']],
    'the same constant reached through `parent`' => ['parent::FROM_BASE', ['ProbeConstantBase.php']],
    // The one shape the two relative names answer differently for. Everywhere else `getDeclaringClass()`
    // walks to the same declaration from either, so a row over an unshadowed constant proves nothing
    // about which class `parent` resolved to.
    'a constant the class REDECLARES, reached through `self`' => ['self::SHADOWED', ['ProbeConstantHolder.php']],
    'the same constant reached through `parent`, which is the base\'s' => ['parent::SHADOWED', ['ProbeConstantBase.php']],
    // …and an interface's, which no walk of the class hierarchy's own files would ever name.
    'a constant declared on an interface' => ['self::FROM_INTERFACE', ['ProbeConstantContract.php']],
    // An expression is not one constant: an arithmetic fold reads both declarations.
    'two constants in one expression' => ['self::OWN + self::FROM_BASE', ['ProbeConstantHolder.php', 'ProbeConstantBase.php']],
    'the same constant twice' => ['self::OWN + self::OWN', ['ProbeConstantHolder.php']],
    // …and every shape that names no declaration a build can read.
    'a literal' => ['409', []],
    'a variable' => ['$statusCode', []],
    '`::class`, which is a name rather than a constant' => ['self::class', []],
    'a constant the class does not have' => ['self::NOPE', []],
    'a class no build can reflect on' => ['\App\Nope\NoSuchClass::CONFLICT', []],
    'a class named by a variable' => ['$class::CONFLICT', []],
    'a constant named by a variable' => ['self::{$name}', []],
    // A global constant carries no declaration site reflection can name.
    'a global constant' => ['PHP_INT_MAX', []],
]);

it('resolves nothing relative for a caller with no class to name', function (string $code): void {
    // The registry path folds a call PHPStan normalised, with no class body around it: `self` there names
    // nothing, and guessing a class would record a file the line never read.
    expect(constantFiles($code, null))->toBe([]);
})->with(['self::OWN', 'static::OWN', 'parent::FROM_BASE']);

it('still names an absolute constant for a caller with no class', function (): void {
    expect(constantFiles('\Docuccino\Inference\PhpStan\Tests\Support\Fixtures\ProbeConstantHolder::OWN', null))
        ->toBe(['ProbeConstantHolder.php']);
});

it('names the file behind a constant reflection reports as a parameter default', function (string $name, ?string $file): void {
    // The other spelling of the same fact: a defaulted status slot
    // (`int $statusCode = HttpStatus::CONFLICT`) is read off the declaration by name, and the value a
    // construction leaving that slot empty passes comes from that other file.
    $found = ConstantSource::fileForName($name, ProbeConstantHolder::class);

    expect($found === null ? null : basename($found))->toBe($file);
})->with([
    'a fully qualified name, as reflection reports one' => [
        'Docuccino\Inference\PhpStan\Tests\Support\Fixtures\ProbeConstantHolder::OWN',
        'ProbeConstantHolder.php',
    ],
    'an inherited one' => [
        'Docuccino\Inference\PhpStan\Tests\Support\Fixtures\ProbeConstantHolder::FROM_BASE',
        'ProbeConstantBase.php',
    ],
    'one written relative to the declaring class' => ['self::FROM_INTERFACE', 'ProbeConstantContract.php'],
    'parent, which is the base rather than the class' => ['parent::FROM_BASE', 'ProbeConstantBase.php'],
    // …and the same name over a REDECLARED constant, where the two really do name two files.
    'parent over a constant the class redeclares' => ['parent::SHADOWED', 'ProbeConstantBase.php'],
    'self over that same constant' => ['self::SHADOWED', 'ProbeConstantHolder.php'],
    'a global constant, which names no file' => ['PHP_INT_MAX', null],
    'a name no class answers to' => ['App\Nope\NoSuchClass::CONFLICT', null],
]);
