<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Docuccino\Inference\PhpStan\Tests\Support\MissingStatusProducers;

/**
 * The reading behind `UnreadStatusTest`'s union check, executed rather than asserted: for every spelling
 * claimed, a method is WRITTEN in it and the reading is asked what it sees.
 *
 * A derivation that reads a spelling instead of a type answers "not a producer" for a method whose return
 * admits a missing status, and that answer is the one that lets a new producer ship without the row that
 * would classify it — a regex keyed on a bare alias name as the whole `@return` was proved blind to
 * `Alias|null` exactly that way, with the suite green and the row deleted.
 *
 * The `false` rows are the other half: a reading that said yes to everything would pass every row above
 * them and guard nothing.
 */
it('reads what a method declares it may answer with, not a spelling of it', function (string $member, bool $seen): void {
    // One shape file and one target, which is how the package writes it: a shape is declared where it
    // belongs and imported where it is answered.
    $scan = static function (string $member): array {
        $shapes = <<<'PHP'
            <?php

            namespace Package;

            /**
             * @phpstan-type StatedRead array{status: int|null, spoke: bool}
             * @phpstan-type Chained StatedRead
             * @phpstan-type Carried list<StatedRead>
             * @phpstan-type Loop array{next: Loop, file: string}
             */
            final class Shapes {}
            PHP;

        $target = <<<PHP
            <?php

            namespace Package;

            /**
             * @phpstan-import-type StatedRead from Shapes
             *
             * @phpstan-type Local array{status: ?int}
             */
            final class Target
            {
                {$member}
            }
            PHP;

        return MissingStatusProducers::in(['/shapes.php' => $shapes, '/target.php' => $target], '/target.php');
    };

    expect($scan($member))->toBe($seen ? ['answer'] : []);
})->with([
    'a native `?int`' => ['private function answer(): ?int { return null; }', true],
    'a native `int|null`' => ['private function answer(): int|null { return null; }', true],
    'a native `null|int`' => ['private function answer(): null|int { return null; }', true],
    'a native `null`, which can answer nothing else' => ['private function answer(): null { return null; }', true],
    'a docblock `@return int|null` over an untyped answer' => ['/** @return int|null */
        private function answer() { return null; }', true],
    'an inline shape' => ['/** @return array{status: int|null, spoke: bool} */
        private function answer(): array { return []; }', true],
    'an inline shape spelled `?int`' => ['/** @return array{status: ?int} */
        private function answer(): array { return []; }', true],
    'an alias, whole' => ['/** @return StatedRead */
        private function answer(): array { return []; }', true],
    'an alias declared in this same file' => ['/** @return Local */
        private function answer(): array { return []; }', true],
    'an alias behind `|null`' => ['/** @return StatedRead|null */
        private function answer(): ?array { return null; }', true],
    'an alias behind `?`' => ['/** @return ?StatedRead */
        private function answer(): ?array { return null; }', true],
    'an alias inside a `list<>`' => ['/** @return list<StatedRead> */
        private function answer(): array { return []; }', true],
    'an alias inside an `array<,>`' => ['/** @return array<string, StatedRead> */
        private function answer(): array { return []; }', true],
    'an alias inside a `[]`' => ['/** @return StatedRead[] */
        private function answer(): array { return []; }', true],
    'an alias that names another alias' => ['/** @return Chained */
        private function answer(): array { return []; }', true],
    'an alias that names a list of another' => ['/** @return Carried */
        private function answer(): array { return []; }', true],
    'a shape carrying a shape' => ['/** @return array{read: StatedRead, file: string} */
        private function answer(): array { return []; }', true],
    // The key is not read at all: a slot renamed is the same invisibility one spelling along, and a
    // method that owes a row it can answer "not a status" in is loud where reading the name is silent.
    'a slot keyed something other than `status`' => ['/** @return array{hint: int|null, spoke: bool} */
        private function answer(): array { return []; }', true],
    'a slot that is optional rather than nullable' => ['/** @return array{status?: int, spoke: bool} */
        private function answer(): array { return []; }', true],
    'a `@phpstan-return` narrowing a wider `@return`' => ['/**
         * @return array
         *
         * @phpstan-return array{status: int|null}
         */
        private function answer(): array { return []; }', true],
    'a `@psalm-return`' => ['/** @psalm-return array{status: int|null} */
        private function answer(): array { return []; }', true],

    'a plain `int`' => ['private function answer(): int { return 404; }', false],
    'a nullable that is not an int' => ['private function answer(): ?string { return null; }', false],
    'a list that may be absent, which is a walk with nothing to descend into' => ['/** @return list<Thrown>|null */
        private function answer(): ?array { return null; }', false],
    'a shape with no slot that can be an int' => ['/** @return array{file: string, spoke: bool} */
        private function answer(): array { return []; }', false],
    'a shape whose int slot is always present' => ['/** @return array{status: int, spoke: bool} */
        private function answer(): array { return []; }', false],
    'a missing status it is HANDED rather than answers with' => ['/** @param array{status: int|null} $read */
        private function answer(array $read): bool { return true; }', false],
    'an alias it never names' => ['private function answer(): array { return []; }', false],
    // Executed rather than assumed: the hop cap is what keeps this a "no" rather than a hang.
    'an alias that names itself' => ['/** @return Loop */
        private function answer(): array { return []; }', false],
    'a null deeper in that no int shares a slot with' => ['/** @return array{read: string|null} */
        private function answer(): array { return []; }', false],
]);

/**
 * The denominator. Every row above is over synthetic source, so all of them would agree with a reading
 * that had stopped finding the package it exists to read; this is the row over the real thing.
 */
it('reads the package it guards, and the shape alias that package declares', function (): void {
    $src = dirname(__DIR__, 2).'/src';

    $texts = [];
    foreach (['Throwing/ThrowAnalyzer.php', 'Throwing/ThrowSiteStatus.php'] as $file) {
        $texts[$src.'/'.$file] = (string) file_get_contents($src.'/'.$file);
    }

    expect(MissingStatusProducers::aliases($texts))->toHaveKey('StatedRead')
        // The method the alias made invisible, and one whose answer is a missing status with no alias in
        // sight — so the reading is not resting on either half alone.
        ->and(MissingStatusProducers::in($texts, $src.'/Throwing/ThrowAnalyzer.php'))
        ->toContain('inDeclaringCallee', 'httpStatus');
});
