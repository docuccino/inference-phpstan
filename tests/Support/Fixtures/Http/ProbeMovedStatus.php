<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The factory idiom with a constructor that NORMALISES its status before forwarding it: `none()` really
 * builds a 400, so publishing the 422 the default names would be a precise false status.
 */
final class ProbeMovedStatus extends HttpException
{
    /**
     * @param  list<string>  $fields
     */
    private function __construct(private readonly array $fields, int $statusCode = 422)
    {
        if ($fields === []) {
            $statusCode = 400;
        }

        parent::__construct($statusCode, 'Rejected.');
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
