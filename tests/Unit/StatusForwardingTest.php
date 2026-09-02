<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\StatusForwarding;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * The parse half of the HttpException status read: which `parent::__construct()` a body makes, what it puts
 * in one slot of it, whether the body writes the variable it forwards, and every `new` the body makes of
 * the class. No types are resolved here, so these run in process; that a real
 * constructor's statements arrive looking like this is the fixture group's job ({@see ThrowStatusTest}).
 *
 * @return array<array-key, Node\Stmt>
 */
function forwardingStmts(string $code): array
{
    return (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code) ?? [];
}

it('reads the one parent constructor call a body makes', function (string $code, bool $found): void {
    expect(StatusForwarding::parentCall(forwardingStmts($code)) !== null)->toBe($found);
})->with([
    'one call' => ["parent::__construct(422, 'no');", true],
    'one call inside a branch' => ["if (\$x) { parent::__construct(422, 'no'); }", true],
    // Two of them is a constructor choosing its status by branch, which is not ONE status the class states.
    'two calls' => ["if (\$x) { parent::__construct(402, 'a'); } else { parent::__construct(403, 'b'); }", false],
    'no call at all' => ['$this->boom = true;', false],
    // A first-class callable holds a placeholder where its arguments go; it calls nothing.
    'a first-class callable' => ['$c = parent::__construct(...);', false],
    'another class\'s constructor' => ["Other::__construct(422, 'no');", false],
    'another parent method' => ['parent::configure(422);', false],
]);

it('refuses the default of a parameter the constructor writes', function (string $code, bool $reassigns): void {
    // The guard on reading a parameter's default as the value the parent received. Every row states the
    // rule from the language rather than from the code: any write to `$statusCode`, in any of the forms a
    // local can be written, means the value forwarded is not the one the default names.
    expect(StatusForwarding::reassigns(forwardingStmts($code), 'statusCode'))->toBe($reassigns);
})->with([
    'nothing but the forwarding' => ['parent::__construct($statusCode, \'no\');', false],
    'another local written' => ['$message = 409;', false],
    'the parameter merely read' => ['$this->code = $statusCode + 1;', false],
    // The write the report was about: a constructor normalising its own status before forwarding it.
    'a conditional reassignment' => ['if ($x) { $statusCode = 400; }', true],
    // …and after the forwarding, which cannot have changed what was forwarded. Refused anyway: position is
    // not read, and refusing costs a pin the class did state where trusting one publishes a false status.
    'a reassignment after the call' => ['parent::__construct($statusCode, \'no\'); $statusCode = 503;', true],
    'a plain reassignment' => ['$statusCode = 400;', true],
    'a compound assignment' => ['$statusCode += 1;', true],
    'an increment' => ['$statusCode++;', true],
    'a reference binding' => ['$other = &$statusCode;', true],
    'a destructuring target' => ['[$a, $statusCode] = $pair;', true],
    'a keyed destructuring target' => ['[\'s\' => $statusCode] = $data;', true],
    'a foreach value' => ['foreach ($codes as $statusCode) { }', true],
    'a foreach key' => ['foreach ($codes as $statusCode => $label) { }', true],
    'an unset' => ['unset($statusCode);', true],
    'a static declaration' => ['static $statusCode = 1;', true],
    'a global declaration' => ['global $statusCode;', true],
    'a catch binding' => ['try { f(); } catch (Throwable $statusCode) { }', true],
    // A write naming no single local may well have landed on this one.
    'a variable variable' => ['$$name = 400;', true],
    'an extract()' => ['extract($data);', true],
    // Nested where a whole-body scan has to reach it.
    'inside a closure the constructor carries' => ['$f = function () use (&$statusCode) { $statusCode = 400; };', true],
]);

it('finds the argument a call puts in a slot, however it was written', function (string $code, int $slot, array $names, ?string $expected): void {
    $call = StatusForwarding::parentCall(forwardingStmts($code));
    $argument = $call === null ? null : StatusForwarding::argumentAt($call, $slot, $names);

    expect(match (true) {
        $argument instanceof Node\Scalar\Int_ => (string) $argument->value,
        $argument instanceof Node\Expr\Variable && is_string($argument->name) => '$'.$argument->name,
        $argument === null => null,
        default => 'other',
    })->toBe($expected);
})->with([
    'positional' => ["parent::__construct(422, 'no');", 0, ['statusCode', 'message'], '422'],
    'a forwarded parameter' => ['parent::__construct($status, $message);', 0, ['statusCode', 'message'], '$status'],
    // Named, and the callee's parameters are what put it in the position a counting reader would miss.
    'named, with the signature known' => ["parent::__construct(message: 'no', statusCode: 422);", 0, ['statusCode', 'message'], '422'],
    'named, with no signature to place it' => ["parent::__construct(message: 'no', statusCode: 422);", 0, [], null],
    'a slot the call never filled' => ['parent::__construct(422);', 3, ['statusCode', 'message', 'previous', 'headers'], null],
    // A spread written out as a plain list IS its arguments; any other spread makes the position opaque.
    'a spread written as a list' => ["parent::__construct(...[422, 'no']);", 0, ['statusCode', 'message'], '422'],
    'a spread of something unreadable' => ['parent::__construct(...$args);', 0, ['statusCode', 'message'], null],
]);

it('finds every `new` a body makes of the class', function (string $code, int $found): void {
    // What the fold over a class's own constructions is handed. Every row states which `new`s PHP would
    // bind to this class, not which of them a reader happens to like: `self` and `static` name it here,
    // its own name names it, and a construction nested in a closure the body carries is still one the
    // body makes. The body is the class's own, which is what the third argument says.
    expect(StatusForwarding::constructionsOf(
        forwardingStmts($code),
        'App\\Exceptions\\ExportRejected',
        'App\\Exceptions\\ExportRejected',
    ))->toHaveCount($found);
})->with([
    'through `self`' => ['return new self($columns);', 1],
    'through `static`' => ['return new static($columns, 409);', 1],
    'by the class\'s own name' => ['return new \\App\\Exceptions\\ExportRejected($columns, 409);', 1],
    'the same name written with no leading slash' => ['return new App\\Exceptions\\ExportRejected($columns);', 1],
    // A first-class callable is a `new` the body makes; that its arguments are supplied out of sight is
    // the FOLD's business ({@see ConstructionStatusTest}), not this scan's.
    'as a first-class callable' => ['$make = new self(...);', 1],
    'two of them' => ['return $x ? new self([]) : new self([], 409);', 2],
    'inside a closure the class carries' => ['return fn (): self => new self($columns, 409);', 1],
    'a different class entirely' => ['return new OtherProblem($columns, 409);', 0],
    'a class named by a variable' => ['return new $class($columns);', 0],
    'nothing constructed at all' => ['return $this->columns;', 0],
]);

it('reads a BASE\'s body by what PHP binds there, not by what the subclass would', function (string $code, int $found): void {
    // The same scan over a body written one class UP, which is where the two relative names stop meaning
    // the same thing. Stated from PHP's own binding: `new static` is late static binding, so a base's
    // builds whichever class was entered — this one included — while `new self` names the class the line
    // is written in, and the base's instance is not the subclass's.
    expect(StatusForwarding::constructionsOf(
        forwardingStmts($code),
        'App\\Exceptions\\ExportRejected',
        'App\\Exceptions\\ProblemBase',
    ))->toHaveCount($found);
})->with([
    'the base\'s `new static`, which builds the subclass' => ['return new static($columns, 409);', 1],
    'the base\'s `new self`, which builds the base' => ['return new self($columns, 409);', 0],
    'the subclass named outright from the base' => ['return new \\App\\Exceptions\\ExportRejected($columns);', 1],
    'the base named outright, which is not the subclass' => ['return new \\App\\Exceptions\\ProblemBase($columns);', 0],
]);
