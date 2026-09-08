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
 * @internal
 */
enum UnreadStatusReason: string
{
    /** `abort($status)` — the argument carrying the status would not fold to a constant. */
    case DynamicArgument = 'dynamic-argument';

    /** The `new`/factory the throw names presented itself and its status would not fold to one value. */
    case DynamicConstruction = 'dynamic-construction';

    /** The throw named no construction, and the class states no single status of its own. */
    case UnstatedByClass = 'unstated-by-class';

    /** The declarations that would state it are outside the project, so this build never read them. */
    case ForeignClass = 'foreign-class';

    /** Completes "the status could not be read: …". */
    public function because(): string
    {
        return match ($this) {
            self::DynamicArgument => 'the status handed to the call is not a constant this build can fold',
            self::DynamicConstruction => 'the construction the throw names does not fold to one status',
            self::UnstatedByClass => 'the throw names no construction, and the class states no single status of its own',
            self::ForeignClass => 'the class is declared outside the project, so the status it sets was never read',
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
            self::DynamicConstruction => 'Say the status as a constant where the exception is built — a literal, a class constant, or the constructor default a construction leaves the slot empty for. Pin it in the class with `parent::__construct(409, …)` if every instance is that status, and otherwise write it at the `throw`, or in the static factory the `throw` names.',
            self::UnstatedByClass => 'Say the status once in the class — `parent::__construct(409, …)`, or the constructor default every construction leaves alone — so a throw that builds it somewhere this build cannot see still names one. Where the class really has several, write the status at each `throw`.',
            self::ForeignClass => null,
        };
    }
}
