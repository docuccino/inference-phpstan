<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * The one status a SET of `throw` sites states — the rule behind
 * {@see ThrowAnalyzer::inDeclaringCallee()}, held apart from the walk that collects the readings so it can
 * be read (and tested) without a PHPStan scope anywhere near it.
 *
 * It is {@see ConstructionStatus::agreedIn()} one level up. That rule is over the constructions ONE throw
 * makes; this one is over the throws one callee writes, and it answers the same way: they either all state
 * the same status or the set states none. What it adds is which of three ways a set can fail to speak, so
 * the caller can say whose code the fold gave up in.
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
 * @phpstan-type StatedRead array{status: int|null, spoke: bool, read: string|null}
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

        // Only a set that folded whole and agreed states a status. Two `throw`s at two statuses are two
        // responses and one call raises whichever ran; one that folded nothing is a response the rest
        // cannot speak for either. Deliberately not order-sensitive: a body pairing a construction that
        // would not fold with one that did has two answers available, and reading them in arrival order
        // would publish whichever the analyser happened to hand over first.
        if ($gaveUp === [] && ! $forClass && count(array_unique($statuses)) === 1) {
            return ['status' => $statuses[0], 'spoke' => true, 'read' => null];
        }

        sort($gaveUp);

        return ['status' => null, 'spoke' => true, 'read' => $gaveUp[0] ?? null];
    }

    /**
     * @return StatedRead
     */
    private static function silent(): array
    {
        return ['status' => null, 'spoke' => false, 'read' => null];
    }
}
