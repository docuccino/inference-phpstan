<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Tagged;

/** The caller chooses the value — a promoted default, an argument, a branch — so nothing is fixed. */
final readonly class ChosenMessage
{
    public ChannelKind $fromArgument;

    public ChannelKind $branched;

    public string $afterReturn;

    public function __construct(public ChannelKind $promoted = ChannelKind::Sms, ChannelKind $kind = ChannelKind::Push, bool $urgent = false)
    {
        $this->fromArgument = $kind;
        if ($urgent) {
            $this->branched = ChannelKind::Push;
        } else {
            $this->branched = ChannelKind::Email;
        }
        if ($urgent) {
            return;
        }
        $this->afterReturn = 'late';
    }
}
