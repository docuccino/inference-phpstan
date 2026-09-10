<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

/**
 * Which fold gave up when a throw carried no readable HTTP status. The response is published under the
 * adapter's unplaced status either way, so this is the part a reader cannot work out from the document:
 * which of four reads was asked, and what would have answered it.
 *
 * Every case answers {@see because()}; the three that name a fold over code an author can be writing in
 * answer {@see remedy()} too. Whether a firing IS one of those is not the reason's call — a status
 * argument folded in a package-shipped action is the same reason in code nobody here can edit — so it is
 * {@see UnreadStatus::isActionable()} that decides, off the file the fold actually read.
 *
 * "Outside the application" means a package, and nothing narrower. An application that writes half its
 * code in a modular PSR-4 root owns every line of it, so {@see ForeignClass} — the one reason nobody can
 * act on — is never the answer for a class the application's own autoload map declares. Asking instead how
 * far interprocedural DESCENT may walk would say the opposite for such a class, and the two questions are
 * separate: the build reads a primed declaration wherever it lives, and walks only where it is bounded.
 *
 * @internal
 */
enum UnreadStatusReason: string
{
    /** `abort($status)` — the argument carrying the status would not fold to a constant. */
    case DynamicArgument = 'dynamic-argument';

    /**
     * A construction presented itself — the `new`/factory the throw names, or the one the callee that
     * DECLARED the throw makes — and its status would not fold to one value.
     */
    case DynamicConstruction = 'dynamic-construction';

    /** Nothing on the way to the throw built the exception, and the class states no single status. */
    case UnstatedByClass = 'unstated-by-class';

    /** The declarations that would state it belong to a package, so this build never read them. */
    case ForeignClass = 'foreign-class';

    /** Completes "the status could not be read: …". */
    public function because(): string
    {
        return match ($this) {
            self::DynamicArgument => 'the status handed to the call is not a constant this build can fold',
            self::DynamicConstruction => 'the construction behind the throw does not fold to one status',
            self::UnstatedByClass => 'neither the throw nor the code that declared it builds the exception, and the class states no single status of its own',
            self::ForeignClass => 'the class is declared in a package, so the status it sets was never read',
        };
    }

    /**
     * What the author can change so the NEXT build reads a number, or null where the fold read a
     * declaration nobody here owns and there is nothing to ask for.
     */
    public function remedy(): ?string
    {
        return match ($this) {
            self::DynamicArgument => 'Write the status as a constant at the call — a literal or a class constant both fold. A status chosen at run time is not one: this build cannot tell which of them the response is. Where the call really can send several, document them with `#[Response(status: …)]` for each.',
            self::DynamicConstruction => 'Give every way this exception is built one constant status: a literal or a class constant in the `new`, or the constructor default a construction leaves the slot empty for — and forward it to `parent::__construct()` untouched, since a constructor that rewrites the status hands the response a number no caller wrote. A factory choosing between two statuses is two responses, so split it into one factory per status, and move a factory a trait writes onto the class itself, where this build can read it. Where the status really is chosen at run time there is no number to read: document the responses with `#[Response(status: …)]` for each.',
            self::UnstatedByClass => 'Build the exception somewhere this throw can be read from: a `throw X::notFound()`, or a guard whose own `throw` names a factory, both state a status where re-throwing one caught from elsewhere cannot. Where the class really carries several responses and nothing here says which, they are separate errors sharing one class — give each its own exception class, or document them with `#[Response(status: …)]`.',
            self::ForeignClass => null,
        };
    }
}
