<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Metadata\DocBlockReader;

/**
 * The docblock prose reader (in-process, adapter-side): summary/description split, @example, and the
 * degradation branches. read() is built on summary(), so this pins the split's own edge cases (G7).
 */
it('splits leading prose into summary (first paragraph) and description (remainder)', function (): void {
    $read = (new DocBlockReader)->read("/**\n * First summary line.\n *\n * Second paragraph with detail.\n */");

    expect($read['summary'])->toBe('First summary line.')
        ->and($read['description'])->toBe('Second paragraph with detail.');
});

it('joins paragraphs beyond the first into the description', function (): void {
    $read = (new DocBlockReader)->read("/**\n * Summary.\n *\n * Second para.\n *\n * Third para.\n */");

    expect($read['summary'])->toBe('Summary.')
        ->and($read['description'])->toBe("Second para.\n\nThird para.");
});

it('leaves the description null for a single-paragraph docblock', function (): void {
    $read = (new DocBlockReader)->read("/**\n * Only one paragraph here.\n */");

    expect($read['summary'])->toBe('Only one paragraph here.')
        ->and($read['description'])->toBeNull();
});

it('returns both null for an empty or absent docblock', function (?string $doc): void {
    expect((new DocBlockReader)->read($doc))->toBe(['summary' => null, 'description' => null]);
})->with([
    'empty docblock' => ['/** */'],
    'null' => [null],
]);

it('reads the first non-empty @example and the leading prose via summary()', function (): void {
    $reader = new DocBlockReader;
    $doc = "/**\n * A summary.\n *\n * @example {\"id\": 1}\n */";

    expect($reader->summary($doc))->toBe('A summary.')
        ->and($reader->example($doc))->toBe('{"id": 1}');
});

it('enumerates @property / @property-read tags as an ordered name => {type, description} map', function (): void {
    $doc = "/**\n * A model.\n *\n * @property int \$id The identifier.\n * @property ?string \$name\n * @property-read string \$slug\n */";

    $properties = (new DocBlockReader)->properties($doc);

    expect(array_keys($properties))->toBe(['id', 'name', 'slug'])
        ->and($properties['id'])->toBe(['type' => 'int', 'description' => 'The identifier.'])
        ->and($properties['name'])->toBe(['type' => '?string', 'description' => null])
        ->and($properties['slug'])->toBe(['type' => 'string', 'description' => null]);
});

it('keeps the first declaration of a duplicated @property name and ignores a nameless tag', function (): void {
    // @property before @property-read; a duplicate keeps the first (more-authoritative) type.
    $doc = "/**\n * @property int \$id\n * @property-read string \$id\n */";

    expect((new DocBlockReader)->properties($doc))->toBe(['id' => ['type' => 'int', 'description' => null]]);
});

it('returns no properties for a docblock without @property tags', function (?string $doc): void {
    expect((new DocBlockReader)->properties($doc))->toBe([]);
})->with([
    'prose only' => ["/**\n * Just prose.\n */"],
    'empty' => ['/** */'],
    'null' => [null],
]);
