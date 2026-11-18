<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * The statuses a SET of `throw` sites states — the rule behind {@see ThrowAnalyzer::inDeclaringCallee()},
 * held apart from the walk that collects the readings so it can be read (and tested) without a PHPStan scope
 * anywhere near it.
 *
 * A set that folded whole states every status it folded to: two `throw`s at two statuses are two responses,
 * and one call raises whichever ran, so the call answers with both. This is where it parts from
 * {@see ConstructionStatus::agreedIn()} and from the class's own agreement, which speak for every
 * construction of a class ANYWHERE and so may only state one they all share: these are the throws of the one
 * callee this call reaches. A set that did not fold whole states none, and what this adds is which of three
 * ways it can fail to speak, so the caller can say whose code the fold gave up in.
 *
 * A reading is what {@see ThrowAnalyzer::atThrowSite()} answered for one `throw`, plus the file that
 * `throw` is written in:
 *
 *   - `spoke` false — the `throw` built nothing this hop can read (a rethrow, a call one hop further down).
 *     The SET is then incomplete, so nothing here may speak for it and the whole reading is silent, which
 *     leaves the exception class's own agreement entitled to answer. This is the one reading that
 *     discards the others rather than joining them.
 *   - a folded status — a construction that stated one.
 *   - `foldedHere` — an expression written in that file presented itself and would not fold, so that file
 *     is what a notice about it should be judged against.
 *   - neither — a construction into a class forwarding no status slot: it presented itself and there was
 *     no argument at the site to read, so the fold was the CLASS's declarations and `read` stays null.
 *
 * @phpstan-type SiteReading array{status: int|null, spoke: bool, foldedHere: bool, file: string}
 * @phpstan-type StatedRead array{statuses: list<int>, spoke: bool, read: string|null}
 *
 * @internal
 */
final class ThrowSiteStatus
{
    /**
     * @param  list<SiteReading>  $readings  in the order the walk met them; the answer is not
     * @return StatedRead
     */
    public static function stated(array $readings): array
    {
        $statuses = [];
        $gaveUp = [];
        $forClass = false;

        foreach ($readings as $reading) {
            if (! $reading['spoke']) {
                return self::silent();
            }

            if ($reading['status'] !== null) {
                $statuses[] = $reading['status'];
            } elseif ($reading['foldedHere']) {
                $gaveUp[] = $reading['file'];
            } else {
                $forClass = true;
            }
        }

        if ($statuses === [] && $gaveUp === [] && ! $forClass) {
            return self::silent(); // the callee throws this class nowhere the walk could see
        }

        // Only a set that folded whole states its statuses, sorted, so the answer is a function of the set
        // and not of the order the analyser handed it over in. One `throw` that folded nothing is a response
        // the rest cannot speak for: the call may raise it, at a status nobody read.
        if ($gaveUp === [] && ! $forClass) {
            $statuses = array_values(array_unique($statuses));
            sort($statuses);

            return ['statuses' => $statuses, 'spoke' => true, 'read' => null];
        }

        sort($gaveUp);

        return ['statuses' => [], 'spoke' => true, 'read' => $gaveUp[0] ?? null];
    }

    /**
     * @return StatedRead
     */
    private static function silent(): array
    {
        return ['statuses' => [], 'spoke' => false, 'read' => null];
    }
}
