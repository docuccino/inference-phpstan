<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Support\Fixtures;

use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeCollection;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeError;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Payload\ProbeOptional;

/**
 * A DTO written the way spatie/laravel-data DTOs are: promoted constructor properties whose element types can
 * only be stated in a docblock — the constructor's `@param` tags or the promoted parameter's own `@var` —
 * with unqualified class names that nothing but this file's imports resolve. The `ProbeCollection` members
 * cover the other half: a reflected class type that is precise and still says nothing about its elements.
 * Autoloaded (not inline in a test file) so it is visible across Paratest processes; only ever reflected,
 * never instantiated.
 *
 * @property int $magic
 * @property string $ownVar
 */
final class DocBlockTypeProbe extends DocBlockTypeProbeBase
{
    /** A static is never a column, whatever it is typed as. */
    public static array $registry = [];

    /** @var list<ProbeError> */
    public array $ownVar = [];

    /** @var array */
    public $vagueVar = [];

    public array $noTag = [];

    /**
     * @param  list<ProbeError>|ProbeOptional  $errors
     * @param  array<string, int>  $counts
     * @param  list<int>  $late
     * @param  int  $title  deliberately wrong — a native `string` is already precise, so this is never read
     * @param  array  $vague
     * @param  list<ProbeError>  $paramAndVar
     * @param  ProbeCollection<int, ProbeError>  $collection
     * @param  ProbeCollection<int, ProbeError>  $nullableCollection
     * @param  ProbeOptional<int, ProbeError>  $otherCollection
     */
    public function __construct(
        public readonly array|ProbeOptional $errors = new ProbeOptional,
        public readonly array $counts = [],
        public $late = null,
        public readonly string $title = '',
        public $vague = [],

        /**
         * Documented twice over, which a long-lived DTO ends up being.
         *
         * @var list<int>
         */
        public readonly array $paramAndVar = [],

        /** @var array<string, int> */
        public readonly array $ownVarPromoted = [],

        public readonly ProbeCollection $collection = new ProbeCollection,

        public readonly ?ProbeCollection $nullableCollection = null,

        /** @var ProbeCollection<int, ProbeError>|null */
        public readonly ProbeCollection $widenedCollection = new ProbeCollection,

        public readonly ProbeCollection $otherCollection = new ProbeCollection,

        /** @var list<ProbeError> */
        public readonly ProbeCollection $mismatchedCollection = new ProbeCollection,

        /** @var ProbeCollection<int */
        public readonly ProbeCollection $garbledCollection = new ProbeCollection,
    ) {}
}
