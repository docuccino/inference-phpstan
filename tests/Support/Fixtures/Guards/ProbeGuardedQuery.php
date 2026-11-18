<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Guards;

/** A query keeping its guards in methods of its own, reached every way a method can call one. Never run. */
class ProbeGuardedQuery extends ProbeGuardedBase
{
    public function run(self $other, string $dynamic): void
    {
        $this->guard();
        self::staticGuard();
        static::guard();
        $this->shared();
        $this->open();
        $this->inherited();
        $other->otherGuard();
        $this->{$dynamic}();
        $this->undeclared();
    }

    private function guard(): void {}

    private static function staticGuard(): void {}

    private function otherGuard(): void {}

    protected function shared(): void {}

    public function open(): void {}
}
