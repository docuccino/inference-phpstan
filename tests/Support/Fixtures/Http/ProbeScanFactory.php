<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The commonest factory shape: no constructor of its own, so the framework's runs, and the factory builds
 * the exception with the status before decorating it. The class states no status; this factory does.
 */
final class ProbeScanFactory extends HttpException
{
    public ?string $detail = null;

    public static function detected(?string $detail = null): self
    {
        $exception = new self(422, 'The upload was rejected.');
        $exception->detail = $detail;

        return $exception;
    }

    /** Not a factory at all — an instance method a `throw X::detail()` could never name. */
    public function detail(): ?string
    {
        return $this->detail;
    }
}
