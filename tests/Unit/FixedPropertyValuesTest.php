<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\ChannelKind;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\ChosenMessage;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\ClonedMessage;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\CopiedMessage;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\EmailMessage;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\MistypedMessage;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\MutableMessage;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\OpenMessage;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\PushMessage;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged\SmsMessage;

/*
 * A property a class fixes to one value is typed as that value. The claim is about every instance, so it
 * is made only where PHP itself guarantees it: readonly (a second write throws), final (no subclass
 * constructs one differently), assigned by the class's own constructor at top level with nothing able to
 * leave before it. Real reflection over autoloaded probes, each shaped the way such a class is written.
 */
function fixedPropertyType(string $class, string $property): DType
{
    foreach ((new ClassMetadataFactory)->forClass(new ClassRef($class))->properties as $meta) {
        if ($meta->name === $property) {
            return $meta->type;
        }
    }

    throw new RuntimeException('no such property: '.$property);
}

it('types a property the class fixes as the one value every instance holds', function (string $class, string $property, LiteralT $expected): void {
    // The literal is the WIRE value: a backed enum case serialises as its backing value, which is what a
    // client reads in the field.
    expect(fixedPropertyType($class, $property))->toEqual($expected);
})->with([
    'a backed enum case in a readonly class' => [EmailMessage::class, 'channel', new LiteralT('email')],
    'a string literal after other work' => [SmsMessage::class, 'channel', new LiteralT('sms')],
    'a class constant holding an enum case' => [PushMessage::class, 'channel', new LiteralT('push')],
    'an int literal' => [PushMessage::class, 'version', new LiteralT(2)],
    // A copy without properties carries the value over as it was.
    'a class that clones itself plainly' => [CopiedMessage::class, 'channel', new LiteralT('copy')],
]);

it('keeps the declared type wherever an instance could hold another value', function (string $class, string $property, DType $declared): void {
    expect(fixedPropertyType($class, $property))->toEqual($declared);
})->with([
    'a class a subclass could construct differently' => [OpenMessage::class, 'channel', new EnumT(ChannelKind::class, ['Email', 'Sms', 'Push'])],
    'a property anyone may write afterwards' => [MutableMessage::class, 'channel', new EnumT(ChannelKind::class, ['Email', 'Sms', 'Push'])],
    'a promoted property with a default' => [ChosenMessage::class, 'promoted', new EnumT(ChannelKind::class, ['Email', 'Sms', 'Push'])],
    'a value passed in' => [ChosenMessage::class, 'fromArgument', new EnumT(ChannelKind::class, ['Email', 'Sms', 'Push'])],
    'a value chosen by a branch' => [ChosenMessage::class, 'branched', new EnumT(ChannelKind::class, ['Email', 'Sms', 'Push'])],
    // The return may leave the property uninitialised, and a later method could then write it.
    'an assignment a return can skip' => [ChosenMessage::class, 'afterReturn', ScalarT::string()],
    'a class whose __clone may re-initialise it' => [ClonedMessage::class, 'channel', ScalarT::string()],
    // Under strict types neither constructs; under loose ones the instance holds `5` and `'7'`. Either
    // way no instance holds the value as written.
    'a string written into an int' => [MistypedMessage::class, 'priority', ScalarT::int()],
    'an int written into a string' => [MistypedMessage::class, 'code', ScalarT::string()],
    'a property the constructor never assigns' => [SmsMessage::class, 'segments', ScalarT::int()],
]);

it('records the enum a fixed value was copied from, so a changed backing value invalidates it', function (): void {
    $files = (new ClassMetadataFactory)->forClass(new ClassRef(PushMessage::class))->dependencyFiles;

    // The property type no longer names the enum, so without this the enum's file would drop out of the
    // key and a warm build would keep publishing the old value.
    expect($files)->toContain((string) (new ReflectionEnum(ChannelKind::class))->getFileName())
        ->and($files)->toContain((string) (new ReflectionClass(PushMessage::class))->getFileName());
});

/**
 * Declare the class `Retagged` from source that only parses on a newer PHP than the suite's floor, so it
 * is written to a file of its own and loaded at run time rather than committed. Returns its name.
 */
function retaggedClass(string $source): string
{
    $namespace = 'Docuccino\\Inference\\PhpStan\\Tests\\Runtime\\R'.md5($source);
    $file = sys_get_temp_dir().'/docuccino-retagged-'.getmypid().'-'.md5($source).'.php';
    file_put_contents($file, "<?php\n\nnamespace {$namespace};\n\n{$source}\n");

    // Kept until the process ends: the reader under test parses the class's file, and a file already
    // removed reads as unreadable, which answers "not fixed" for every row whatever the source says.
    register_shutdown_function(static fn () => @unlink($file));
    require_once $file;

    return $namespace.'\\Retagged';
}

it('keeps the declared type where a copy may be re-tagged', function (string $version, string $source): void {
    // PHP 8.5's clone-with re-initialises a readonly property on the copy without any `__clone`, from
    // any scope with set access — the class's own body, an ancestor's (readonly is implicitly
    // protected(set)), or a trait either uses — and a `public(set)` property lets any code do it. A copy
    // then holds another value than the constructor wrote, so none is pinned.
    if (version_compare(PHP_VERSION, $version, '<')) {
        $this->markTestSkipped('needs PHP '.$version);
    }

    expect(fixedPropertyType(retaggedClass($source), 'channel'))->toEqual(ScalarT::string());
})->with([
    'a clone with properties in its own body' => ['8.5.0', <<<'PHP'
        final readonly class Retagged
        {
            public string $channel;

            public function __construct() { $this->channel = 'email'; }

            public function asPush(): static { return clone($this, ['channel' => 'push']); }
        }
        PHP],
    'a clone with properties in a trait it uses' => ['8.5.0', <<<'PHP'
        trait Retags
        {
            public function retag(string $channel): static { return clone($this, ['channel' => $channel]); }
        }

        final readonly class Retagged
        {
            use Retags;

            public string $channel;

            public function __construct() { $this->channel = 'email'; }
        }
        PHP],
    'a clone with properties in an ancestor' => ['8.5.0', <<<'PHP'
        abstract class Base
        {
            public function with(array $changes): static { return clone($this, $changes); }
        }

        final class Retagged extends Base
        {
            public readonly string $channel;

            public function __construct() { $this->channel = 'email'; }
        }
        PHP],
    'a clone with properties in a trait an ancestor uses' => ['8.5.0', <<<'PHP'
        trait Retags
        {
            public function retag(string $channel): static { return clone($this, ['channel' => $channel]); }
        }

        abstract class Grandparent
        {
            use Retags;
        }

        abstract class Base extends Grandparent {}

        final class Retagged extends Base
        {
            public readonly string $channel;

            public function __construct() { $this->channel = 'email'; }
        }
        PHP],
    'clone taken as a callable' => ['8.5.0', <<<'PHP'
        final readonly class Retagged
        {
            public string $channel;

            public function __construct() { $this->channel = 'email'; }

            public function copier(): \Closure { return clone(...); }
        }
        PHP],
    'a property any code may set' => ['8.4.0', <<<'PHP'
        final class Retagged
        {
            public(set) readonly string $channel;

            public function __construct() { $this->channel = 'email'; }
        }
        PHP],
]);

it('pins the same class once nothing in its scope clones with properties', function (): void {
    // The control for the rows above: the reader sees a runtime-written class at all, so a row answering
    // the declared type answers it for the clone-with and not for an unreadable file. A plain clone
    // copies the tag as written.
    $class = retaggedClass(<<<'PHP'
        abstract class Base
        {
            public function copy(): static { return clone $this; }
        }

        final class Retagged extends Base
        {
            public readonly string $channel;

            public function __construct() { $this->channel = 'email'; }
        }
        PHP);

    expect(fixedPropertyType($class, 'channel'))->toEqual(new LiteralT('email'));
});

it('reads the re-tagging premise off PHP itself', function (): void {
    // The premise of the first row above, from the runtime rather than from the reader under test.
    if (version_compare(PHP_VERSION, '8.5.0', '<')) {
        $this->markTestSkipped('clone-with needs PHP 8.5');
    }

    $class = retaggedClass(<<<'PHP'
        final readonly class Retagged
        {
            public string $channel;

            public function __construct() { $this->channel = 'email'; }

            public function asPush(): static { return clone($this, ['channel' => 'push']); }
        }
        PHP);

    expect((new $class)->asPush()->channel)->toBe('push');
});

it('reads the ancestor re-tagging premise off PHP itself', function (): void {
    // An ancestor's method re-initialises a readonly property its final subclass declares, because
    // readonly is implicitly protected(set) — the premise of the ancestor rows above, from the runtime.
    if (version_compare(PHP_VERSION, '8.5.0', '<')) {
        $this->markTestSkipped('clone-with needs PHP 8.5');
    }

    $class = retaggedClass(<<<'PHP'
        abstract class Base
        {
            public function with(array $changes): static { return clone($this, $changes); }
        }

        final class Retagged extends Base
        {
            public readonly string $channel;

            public function __construct() { $this->channel = 'email'; }
        }
        PHP);

    expect((new $class)->with(['channel' => 'push'])->channel)->toBe('push');
});
