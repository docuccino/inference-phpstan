<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Throwing\StatusForwarding;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * The parse half of the HttpException status read: which `parent::__construct()` a body makes, what it puts
 * in one slot of it, whether the body writes the variable it forwards, and whether the class ever builds
 * itself writing that same slot. No types are resolved here, so these run in process; that a real
 * constructor's statements arrive looking like this is the fixture group's job ({@see ThrowCasesTest}).
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

it('spots a construction that writes the slot itself', function (string $code, bool $writes): void {
    expect(StatusForwarding::writesSlot(
        forwardingStmts($code),
        'App\\Exceptions\\ExportRejected',
        1,
        ['columns', 'statusCode'],
    ))->toBe($writes);
})->with([
    'a factory taking the default' => ['return new self($columns);', false],
    'a factory passing its own status' => ['return new self($columns, 409);', true],
    'through `static`' => ['return new static($columns, 409);', true],
    'by the class\'s own name' => ['return new \\App\\Exceptions\\ExportRejected($columns, 409);', true],
    'named rather than counted' => ['return new self(statusCode: 409, columns: []);', true],
    // Past an unreadable spread the argument may well be there, and reading its absence as "the default was
    // taken" would publish a status the call never passed.
    'behind an unreadable spread' => ['return new self(...$args);', true],
    'behind a spread written out short' => ['return new self(...[$columns]);', false],
    'behind a spread written out in full' => ['return new self(...[$columns, 409]);', true],
    // A callable's arguments are supplied somewhere this build cannot read.
    'as a first-class callable' => ['$make = new self(...);', true],
    'a different class entirely' => ['return new OtherProblem($columns, 409);', false],
    'nothing constructed at all' => ['return $this->columns;', false],
    // A construction nested in a closure is still one the class makes.
    'inside a closure the class carries' => ['return fn (): self => new self($columns, 409);', true],
]);
