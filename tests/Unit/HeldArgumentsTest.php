<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\HeldArguments;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Held\HeldProblem;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Held\HeldReadonlyProblem;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Held\InheritedHeldProblem;
use PhpParser\Node;
use PHPStan\Reflection\ParameterReflection;

/*
 * What one call's parameters hold. A body read for that call is typed from it, so a parameter answered
 * wrongly is a body or a status the call never sends: a value written for another parameter, a default the
 * call did not take, or one argument standing for a variadic list.
 */

/** @return list<array{name: string, variadic: bool}> */
function heldParameters(string ...$names): array
{
    return array_map(static fn (string $name): array => ['name' => ltrim($name, '.'), 'variadic' => str_starts_with($name, '...')], array_values($names));
}

/** @param  array<string, Node\Expr|null>  $held */
function heldNames(array $held): array
{
    return array_map(static fn (?Node\Expr $arg): ?string => match (true) {
        $arg === null => null,
        $arg instanceof Node\Expr\Variable && is_string($arg->name) => '$'.$arg->name,
        $arg instanceof Node\Scalar\Int_ => (string) $arg->value,
        default => '?',
    }, $held);
}

it('reads what each parameter holds off the arguments as written', function (array $args, array $expected): void {
    expect(heldNames(HeldArguments::of(heldParameters('status', 'title', 'detail', '...extensions'), $args)))->toBe($expected);
})->with([
    // A parameter the call passes nothing holds its default, which the reader asks the analyser for.
    'positional' => [[new Node\Arg(new Node\Scalar\Int_(409)), new Node\Arg(new Node\Expr\Variable('title'))], ['status' => '409', 'title' => '$title', 'detail' => null]],
    // A named argument lands on its parameter wherever it is written.
    'named' => [[new Node\Arg(new Node\Expr\Variable('detail'), name: new Node\Identifier('detail')), new Node\Arg(new Node\Scalar\Int_(401), name: new Node\Identifier('status'))], ['status' => '401', 'title' => null, 'detail' => '$detail']],
    // Past a spread nothing can say what a parameter holds, and the default is not something the call took.
    'after a spread' => [[new Node\Arg(new Node\Scalar\Int_(500)), new Node\Arg(new Node\Expr\Variable('rest'), unpack: true)], ['status' => '500']],
    // One argument is not what a variadic holds.
    'into a variadic' => [[new Node\Arg(new Node\Scalar\Int_(500)), new Node\Arg(new Node\Expr\Variable('t')), new Node\Arg(new Node\Expr\Variable('d')), new Node\Arg(new Node\Expr\Variable('e'))], ['status' => '500', 'title' => '$t', 'detail' => '$d']],
]);

it('names the parameter a variable is handed to whole', function (array $args, ?string $expected): void {
    expect(HeldArguments::receiving(heldParameters('request', 'e', '...more'), $args, 'e'))->toBe($expected);
})->with([
    'by position' => [[new Node\Arg(new Node\Expr\Variable('request')), new Node\Arg(new Node\Expr\Variable('e'))], 'e'],
    'by name, to another parameter' => [[new Node\Arg(new Node\Expr\Variable('e'), name: new Node\Identifier('request'))], 'request'],
    // A read of it is not it.
    'a read of it' => [[new Node\Arg(new Node\Expr\MethodCall(new Node\Expr\Variable('e'), 'getMessage'))], null],
    // A variadic holds a list of what it is handed.
    'into a variadic' => [[new Node\Arg(new Node\Expr\Variable('r')), new Node\Arg(new Node\Expr\Variable('x')), new Node\Arg(new Node\Expr\Variable('e'))], null],
    'not handed at all' => [[], null],
]);

it('takes the parameters as the analyser declares them', function (): void {
    $parameter = static function (string $name, bool $variadic): ParameterReflection {
        $mock = Mockery::mock(ParameterReflection::class);
        $mock->allows('getName')->andReturn($name);
        $mock->allows('isVariadic')->andReturn($variadic);

        return $mock;
    };

    expect(HeldArguments::parameters([$parameter('e', false), $parameter('more', true)]))
        ->toBe([['name' => 'e', 'variadic' => false], ['name' => 'more', 'variadic' => true]]);
});

it('holds only the properties a constructor promotes readonly to what it was built with', function (string $class, array $expected): void {
    // A writable promoted property may hold anything by the time a method reads it, and one the body writes
    // holds whatever the body wrote there, which is not the argument itself.
    expect(HeldArguments::promotedReadonly(new ReflectionClass($class)))->toBe($expected);
})->with([
    'promoted, writable, and written in the body' => [HeldProblem::class, ['status', 'detail']],
    'a readonly class' => [HeldReadonlyProblem::class, ['status', 'title']],
    'an inherited constructor' => [InheritedHeldProblem::class, ['status', 'detail']],
    'no constructor' => [stdClass::class, []],
]);

it('reads the object a factory builds only where it builds one', function (array $returned, bool $found): void {
    expect(HeldArguments::soleConstruction($returned))->toBe($found ? $returned[0] : null);
})->with(function (): array {
    $new = new Node\Expr\New_(new Node\Name('Problem'), [new Node\Arg(new Node\Expr\Variable('status'))]);

    return [
        'one `new`' => [[$new], true],
        // Two returns are two objects, and neither is the one this call built.
        'two returns' => [[$new, $new], false],
        'a `new` of a class held in a variable' => [[new Node\Expr\New_(new Node\Expr\Variable('class'))], false],
        'something else' => [[new Node\Expr\Variable('problem')], false],
        'a bare return' => [[null], false],
        'no return' => [[], false],
    ];
});
