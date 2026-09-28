<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Support\ContentTypeHeader;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

/**
 * The one reading of a `Content-Type` written at a call site, which every media type the refiner recovers
 * goes through — a header array handed to a response's constructor or to `->withHeaders()`, and the name
 * a `->header()` link sets. A header name is case-insensitive on the wire, and anything short of a constant
 * string on both sides is "nothing recovered here", never "no content type".
 */
it('names the Content-Type header however it is spelled, and no other', function (string $name, bool $names): void {
    expect(ContentTypeHeader::names($name))->toBe($names);
})->with([
    ['Content-Type', true],
    ['content-type', true],
    ['CONTENT-TYPE', true],
    ['Content-Length', false],
    ['X-Content-Type-Options', false],
    ['', false],
]);

it('reads the media type a header array states', function (string $headers, ?string $mediaType): void {
    $expr = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$headers.';')[0]->expr;

    // The scope types each constant as itself and anything else as a string nobody folded, which is what
    // the analyser answers for `$type` read off configuration.
    $scope = $this->createStub(Scope::class);
    $scope->method('getType')->willReturnCallback(static fn (Node\Expr $e): Type => match (true) {
        $e instanceof Node\Scalar\String_ => new ConstantStringType($e->value),
        $e instanceof Node\Scalar\Int_ => new ConstantIntegerType($e->value),
        default => new StringType,
    });

    expect(ContentTypeHeader::inArray($expr, $scope))->toBe($mediaType);
})->with([
    'the header' => ["['Content-Type' => 'application/problem+json']", 'application/problem+json'],
    'spelled in lower case, beside another' => ["['X-Trace' => 'a', 'content-type' => 'text/csv']", 'text/csv'],
    'a value nobody folded' => ["['Content-Type' => \$type]", null],
    'a name nobody folded' => ["[\$name => 'text/csv']", null],
    'a value that is not a string' => ["['Content-Type' => 415]", null],
    'a list with no names at all' => ["['text/csv']", null],
    'some other header only' => ["['X-Trace' => 'a']", null],
    'not an array literal' => ['$headers', null],
]);
