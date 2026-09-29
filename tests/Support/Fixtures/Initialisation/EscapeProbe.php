<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation;

/** The methods a constructor snippet may hand its work to, one per way the analyser does or does not follow. */
final class EscapeProbe extends EscapeParent
{
    public string $title;

    public string $type;

    public function __construct()
    {
        parent::__construct();
    }

    public static function normalise(string $value): string
    {
        return $value;
    }

    private function named(): void
    {
        $this->title = 'x';
    }

    // Reflection reports this method at its `function` line and the parser at its attribute's.
    #[\Deprecated]
    private function attributed(): void
    {
        $this->title = 'x';
    }

    private function nested(): void
    {
        $this->named();
    }

    private function dynamic(string $key): void
    {
        $this->{$key} = 'x';
    }
}
