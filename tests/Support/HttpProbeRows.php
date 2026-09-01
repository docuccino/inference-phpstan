<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support;

use FilesystemIterator;

/**
 * The one table of what every HTTP probe owes, and the directory it has to account for.
 *
 * `tests/Support/Fixtures/Http/` is a hand-maintained full set: a probe added there and named in no dataset
 * proves nothing while the suite stays green. So the rows live here, the directory is read back, and
 * {@see coveredClasses()} is compared against {@see probeClasses()}.
 *
 * The two answers are ONE row rather than two datasets. They divide the same domain — what a class pins,
 * and which slot it forwards — and side by side they covered their own halves and nothing between: seven
 * probes had no `statusParameter` row saying what they owe. A member owing no answer carries a row saying
 * so (a trait, a plain class) rather than falling in the gap.
 */
final class HttpProbeRows
{
    private const NAMESPACE = 'Docuccino\\Inference\\PhpStan\\Tests\\Support\\Fixtures\\Http\\';

    /**
     * Every probe, as `[fqcn, the status it pins on all its instances, the slot it forwards]`.
     *
     * @return array<string, array{string, int|null, int|null}>
     */
    public static function statuses(): array
    {
        return [
            // Pinned by a constant reaching the parent — the commonest way an exception IS a status.
            'a literal reaching the parent' => [self::NAMESPACE.'ProbePinned', 422, null],
            // Folding is the source's, never hand-rolled here — a class constant reads like the literal.
            'a class constant reaching the parent' => [self::NAMESPACE.'ProbeConstantPinned', 409, null],
            'a literal two classes up, through a base that adds nothing' => [self::NAMESPACE.'ProbeInherited', 503, null],
            'the base that pins for everything below it' => [self::NAMESPACE.'ProbePinningBase', 410, null],
            // A base that pins leaves a subclass only the message to choose, so the pin is the subclass's.
            'a pin inherited from a base that states it' => [self::NAMESPACE.'ProbeInheritsPin', 410, null],
            'a literal pinned by a class that also has a factory' => [self::NAMESPACE.'ProbePinsWithFactory', 410, null],

            // A private constructor with no factory writing the slot: the default is what every instance
            // carries. The slot is still named, so a construction that fills it can be folded at its site.
            'a private constructor default' => [self::NAMESPACE.'ProbeFactory', 422, 1],
            'a private constructor default behind an initialiser that constructs' => [self::NAMESPACE.'ProbeConstructedDefault', 422, 1],

            // …and every negative, each of which would be a status the code does not state.
            // A constructor that writes the variable it forwards states nothing about the slot either: what
            // a caller puts there is not what the parent receives.
            'a constructor that moves the status before forwarding it' => [self::NAMESPACE.'ProbeMovedStatus', null, null],
            'a constructor that writes the parameter after forwarding it' => [self::NAMESPACE.'ProbeStatusAfterParent', null, null],
            'a factory that writes the slot itself' => [self::NAMESPACE.'ProbeOverridingFactory', null, 0],
            'a PUBLIC constructor default' => [self::NAMESPACE.'ProbePublicDefault', null, 0],
            'factories in a trait, written in another file' => [self::NAMESPACE.'ProbeTraitFactory', null, 0],
            'a constructor choosing its status by branch' => [self::NAMESPACE.'ProbeBranching', null, null],
            'a constructor that never reaches its parent' => [self::NAMESPACE.'ProbeNoParentCall', null, null],
            // A constant that is no HTTP status: it would become the response key `"0"`.
            'a pin outside the range a status can take' => [self::NAMESPACE.'ProbeOutOfRangePin', null, null],
            'a default outside the range a status can take' => [self::NAMESPACE.'ProbeOutOfRangeDefault', null, 0],

            // No constructor below `HttpException` adds one, so its own runs and argument 0 IS the status.
            'no constructor at all — the status is an argument' => [self::NAMESPACE.'ProbeNoConstructor', null, 0],
            'an abstract base that adds nothing' => [self::NAMESPACE.'ProbeBase', null, 0],
            'an abstract base carrying only a factory' => [self::NAMESPACE.'ProbeFactoryBase', null, 0],
            'a subclass whose only factory is its base\'s' => [self::NAMESPACE.'ProbeInheritsFactory', null, 0],
            'a class whose factory builds it two ways' => [self::NAMESPACE.'ProbeBranchingFactory', null, 0],
            'a class whose factory builds through another' => [self::NAMESPACE.'ProbeIndirectFactory', null, 0],
            'a class whose factory names its arguments' => [self::NAMESPACE.'ProbeNamedFactory', null, 0],
            'a class whose factory builds then decorates' => [self::NAMESPACE.'ProbeScanFactory', null, 0],
            'a class whose factory spreads its arguments' => [self::NAMESPACE.'ProbeSpreadFactory', null, 0],

            // Members that owe no answer, carrying a row saying so rather than falling in the gap.
            'a trait, which is not a class at all' => [self::NAMESPACE.'ProbeMakesItself', null, null],
            'a class that is not an HttpException' => [self::NAMESPACE.'ProbePlain', null, null],
            'a plain object the probes use as an observable' => [self::NAMESPACE.'ProbeSideEffect', null, null],
        ];
    }

    /**
     * Every class-like the fixtures directory ships, by FQCN. One file per class-like, which the same
     * directory's PSR-4 mapping already requires.
     *
     * @return list<string>
     */
    public static function probeClasses(): array
    {
        $found = [];
        foreach (new FilesystemIterator(__DIR__.'/Fixtures/Http', FilesystemIterator::SKIP_DOTS) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = self::NAMESPACE.$file->getBasename('.php');
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    public static function coveredClasses(): array
    {
        $covered = array_map(static fn (array $row): string => $row[0], array_values(self::statuses()));
        sort($covered);

        return $covered;
    }
}
