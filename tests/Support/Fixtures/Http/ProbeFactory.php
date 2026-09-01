<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The static-factory idiom, with nothing able to build it from outside and no factory writing the slot. */
final class ProbeFactory extends HttpException
{
    /**
     * @param  list<string>  $fields
     */
    private function __construct(private readonly array $fields, int $statusCode = 422)
    {
        parent::__construct($statusCode, 'Rejected.');
    }

    /**
     * @param  list<string>  $fields
     */
    public static function forFields(array $fields): self
    {
        return new self($fields);
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
