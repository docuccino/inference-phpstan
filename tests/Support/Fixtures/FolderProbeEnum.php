<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Docuccino\Inference\PhpStan\Analysis\EnumAccessorFolder;

/**
 * A backed enum with a `match ($this)` accessor method — the shape {@see EnumAccessorFolder}
 * resolves. Autoloaded (not inline in a test file) so it is visible across Paratest processes.
 */
enum FolderProbeEnum: string
{
    case Alpha = 'https://errors.test/alpha';
    case Beta = 'https://errors.test/beta';

    public function status(): int
    {
        return match ($this) {
            self::Alpha => 201,
            self::Beta => 202,
        };
    }
}
