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
 * The three answers are ONE row rather than three datasets. They divide the same domain — what a class
 * pins on every instance, which slot it forwards, and what its own constructions agree on — and side by
 * side two of them covered their own halves and nothing between: seven probes had no `statusParameter` row
 * saying what they owe. A member owing no answer carries a row saying so (a trait, a plain class) rather
 * than falling in the gap.
 *
 * The three also have to agree with each other, which no one of them can say: a class that pins a status
 * may not disagree with what its own constructions build, and a slot no class forwards leaves nothing for
 * the constructions to be read in. {@see HttpExceptionStatusTest} states those two rules over the table.
 */
final class HttpProbeRows
{
    private const NAMESPACE = 'Docuccino\\Inference\\PhpStan\\Tests\\Support\\Fixtures\\Http\\';

    /**
     * Every probe, as `[fqcn, the status it pins on all its instances, the slot it forwards, the status
     * every construction it writes of itself agrees on]`.
     *
     * @return array<string, array{string, int|null, int|null, int|null}>
     */
    public static function statuses(): array
    {
        return [
            // Pinned by a constant reaching the parent — the commonest way an exception IS a status.
            'a literal reaching the parent' => [self::NAMESPACE.'ProbePinned', 422, null, null],
            // Folding is the source's, never hand-rolled here — a class constant reads like the literal.
            'a class constant reaching the parent' => [self::NAMESPACE.'ProbeConstantPinned', 409, null, null],
            'a literal two classes up, through a base that adds nothing' => [self::NAMESPACE.'ProbeInherited', 503, null, null],
            'the base that pins for everything below it' => [self::NAMESPACE.'ProbePinningBase', 410, null, null],
            // A base that pins leaves a subclass only the message to choose, so the pin is the subclass's.
            'a pin inherited from a base that states it' => [self::NAMESPACE.'ProbeInheritsPin', 410, null, null],
            'a literal pinned by a class that also has a factory' => [self::NAMESPACE.'ProbePinsWithFactory', 410, null, null],

            // A private constructor with no factory writing the slot: the default is what every instance
            // carries. The slot is still named, so a construction that fills it can be folded at its site.
            'a private constructor default' => [self::NAMESPACE.'ProbeFactory', 422, 1, 422],
            'a private constructor default behind an initialiser that constructs' => [self::NAMESPACE.'ProbeConstructedDefault', 422, 1, 422],
            // …and one whose factories write the slot and AGREE, which is one status the class has too:
            // reading a written slot as a disqualification left this class stating nothing at all.
            'factories agreeing on the status they write' => [self::NAMESPACE.'ProbeAgreeingFactories', 409, 0, 409],

            // …and every negative, each of which would be a status the code does not state.
            // A constructor that writes the variable it forwards states nothing about the slot either: what
            // a caller puts there is not what the parent receives.
            'a constructor that moves the status before forwarding it' => [self::NAMESPACE.'ProbeMovedStatus', null, null, null],
            'a constructor that writes the parameter after forwarding it' => [self::NAMESPACE.'ProbeStatusAfterParent', null, null, null],
            'a factory that writes the slot itself' => [self::NAMESPACE.'ProbeOverridingFactory', null, 0, null],
            'a PUBLIC constructor default' => [self::NAMESPACE.'ProbePublicDefault', null, 0, null],
            // …and the same default with a factory taking it. The class still pins nothing — a caller may
            // pass another — and what its own factory builds is the weaker answer a throw with no
            // construction of its own falls back to.
            'a PUBLIC constructor default with a factory taking it' => [self::NAMESPACE.'ProbePublicWithFactory', null, 0, 409],
            'factories in a trait, written in another file' => [self::NAMESPACE.'ProbeTraitFactory', null, 0, null],
            // …and the same trait beside a factory the class DOES declare, which agrees with itself at a
            // status that is only half the class's: reading it would publish a 423 for a 409 as well.
            'a private constructor and a factory of its own, beside one in a trait' => [self::NAMESPACE.'ProbeTraitAndOwnFactory', null, 0, null],
            'a constructor choosing its status by branch' => [self::NAMESPACE.'ProbeBranching', null, null, null],
            'a constructor that never reaches its parent' => [self::NAMESPACE.'ProbeNoParentCall', null, null, null],
            // A constant that is no HTTP status: it would become the response key `"0"`.
            'a pin outside the range a status can take' => [self::NAMESPACE.'ProbeOutOfRangePin', null, null, null],
            // …and the same refusal over a constant declared in another file, which decided the answer and
            // so is a dependency whether or not the fold went on to succeed.
            'a pin outside the range, read from another file' => [self::NAMESPACE.'ProbeOutOfRangeConstantPin', null, null, null],
            'a default outside the range a status can take' => [self::NAMESPACE.'ProbeOutOfRangeDefault', null, 0, null],

            // No constructor below `HttpException` adds one, so its own runs and argument 0 IS the status.
            'no constructor at all — the status is an argument' => [self::NAMESPACE.'ProbeNoConstructor', null, 0, null],
            'an abstract base that adds nothing' => [self::NAMESPACE.'ProbeBase', null, 0, null],
            'an abstract base carrying only a factory' => [self::NAMESPACE.'ProbeFactoryBase', null, 0, 503],
            // A base's `new static(…)` builds the SUBCLASS, so a subclass declaring nothing of its own is
            // still built exactly one way and states that status as surely as the base does.
            'a subclass whose only factory is its base\'s' => [self::NAMESPACE.'ProbeInheritsFactory', null, 0, 503],
            // …and one that adds a factory of its own has two, which is no one status. Reading only its own
            // declared code answered the 413 for a class the base builds as a 503.
            'a subclass with a factory of its own under a base that builds' => [self::NAMESPACE.'ProbeOwnAndInheritedFactory', null, 0, null],
            // A base whose factory names `self` builds the BASE, so the base states 410 and the subclass
            // states nothing: the same walk answering and declining on one hierarchy.
            'a base whose factory builds `self`' => [self::NAMESPACE.'ProbeSelfBuildingBase', null, 0, 410],
            'a subclass of the base that builds `self`' => [self::NAMESPACE.'ProbeInheritsSelfFactory', null, 0, null],
            // The trait gate is owed per ANCESTOR, not once for the class. Each of these builds itself two
            // ways — 410 in front of the read, 409 in the trait's file it never opens — so the visible half
            // agrees with itself at a status that is only half the class's.
            'a base carrying one factory in a trait and one of its own' => [self::NAMESPACE.'ProbeBaseWithTrait', null, 0, null],
            'a subclass whose BASE uses the trait, which it does not' => [self::NAMESPACE.'ProbeInheritsTraitFactory', null, 0, null],
            'a class whose factory builds it two ways' => [self::NAMESPACE.'ProbeBranchingFactory', null, 0, null],
            'a class whose factory builds through another' => [self::NAMESPACE.'ProbeIndirectFactory', null, 0, null],
            'a class whose factory names its arguments' => [self::NAMESPACE.'ProbeNamedFactory', null, 0, 409],
            'a class whose factory builds then decorates' => [self::NAMESPACE.'ProbeScanFactory', null, 0, 422],
            'a class whose factory spreads its arguments' => [self::NAMESPACE.'ProbeSpreadFactory', null, 0, null],

            // Members that owe no answer, carrying a row saying so rather than falling in the gap.
            'a trait, which is not a class at all' => [self::NAMESPACE.'ProbeMakesItself', null, null, null],
            'a class that is not an HttpException' => [self::NAMESPACE.'ProbePlain', null, null, null],
            'a plain object the probes use as an observable' => [self::NAMESPACE.'ProbeSideEffect', null, null, null],
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
