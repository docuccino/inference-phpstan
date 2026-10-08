<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Metadata\ConstructionEscape;
use Docuccino\Inference\PhpStan\Metadata\ReachedStatements;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\DescribingProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\EscapeProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\PrivateProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\SilencedProbe;

/*
 * A constructor path that skips `title` proves the key may be left out only where nothing assigns it that
 * the analyser does not see. It follows one level of `$this->method()` into a method the class declares and
 * nothing else, so every other way a constructor hands `$this` on leaves the skip unproved, and the snippets
 * that hand nothing on leave it standing. Each snippet is a constructor body of `EscapeProbe`.
 */
it('says whether anything unfollowed may assign the property', function (string $body, bool $escapes): void {
    $probe = new ReflectionClass(EscapeProbe::class);

    expect((new ConstructionEscape)->possible($probe, $probe, 'title', escapeProbeBody($body)))->toBe($escapes);
})->with([
    // Nothing handed on: the constructor's own paths are the whole story.
    'a named write' => ['$this->title = "x";', false],
    'a method the class declares, followed' => ['$this->named();', false],
    // Found by name: the attribute above it is where the parser starts the method, not where reflection does.
    'an attributed method the class declares, followed' => ['$this->attributed();', false],
    'a static method' => ['$x = self::normalise("x");', false],
    'another property passed along' => ['$x = strlen($this->type);', false],
    'a static closure' => ['$x = static fn () => 1;', false],
    'another object written' => ['$x = new \\stdClass; $x->title = "y";', false],
    // Handed on.
    'a method a parent declares' => ['$this->inherited();', true],
    'a call from inside a followed method' => ['$this->nested();', true],
    'a dynamic write inside a followed method' => ['$this->dynamic("title");', true],
    'a dynamic method name' => ['$m = "named"; $this->$m();', true],
    'a dynamic property write' => ['$this->{"title"} = "x";', true],
    'an alias' => ['$that = $this;', true],
    '$this as an argument' => ['strlen((string) spl_object_id($this));', true],
    'a closure using $this' => ['$f = function () { $this->title = "x"; };', true],
    'an arrow function using $this' => ['$f = fn () => $this->title;', true],
    'the parent constructor' => ['parent::__construct();', true],
    'self:: on an instance method' => ['self::named();', true],
    'static:: on an instance method' => ['static::named();', true],
    'an ancestor named on an instance method' => ['EscapeParent::inherited();', true],
    'an unresolvable static call' => ['self::missing();', true],
    'a dynamic class' => ['$c = "X"; $c::named();', true],
    'the property as an argument, maybe by reference' => ['preg_match("/x/", "x", $this->title);', true],
]);

it('leaves a private constructor\'s skip unproved, since the class\'s named constructors decide', function (): void {
    $probe = new ReflectionClass(PrivateProbe::class);

    expect((new ConstructionEscape)->possible($probe, $probe, 'title', []))->toBeTrue();
});

/*
 * A constructor whose class inherits the property, answered for the `parent::__construct()` it runs by the
 * parent's own reading: that one call is exempt, and the analyser tracks no write to an inherited property
 * at this level, so any use of it here leaves the parent's skip unproved.
 */
it('exempts the parent constructor answered for elsewhere and nothing else', function (string $body, bool $escapes): void {
    $probe = new ReflectionClass(EscapeProbe::class);
    $statements = escapeProbeBody($body);
    $call = null;
    foreach (ReachedStatements::of($statements) as $statement) {
        $call ??= ReachedStatements::parentConstruct($statement);
    }

    expect($call)->not->toBeNull()
        ->and((new ConstructionEscape)->possible($probe, $probe, 'title', $statements, $call))->toBe($escapes);
})->with([
    'the call alone' => ['parent::__construct();', false],
    'other work after it' => ['parent::__construct(); $this->type = "t";', false],
    'a write in a branch after it' => ['parent::__construct(); if (PHP_INT_SIZE > 4) { $this->title = "x"; }', true],
    'a compound write after it' => ['parent::__construct(); $this->title .= "x";', true],
    'a followed helper writing it' => ['parent::__construct(); $this->named();', true],
    '$this handed to the parent' => ['parent::__construct($this);', true],
    'a second parent constructor call' => ['parent::__construct(); parent::__construct();', true],
]);

/*
 * The analyser tracks no unset, so a path through one leaves out a key it reports assigned. Read in the
 * constructor and in each helper it follows, by name or through a dynamic name that may be this one.
 */
it('says whether the constructor or a followed helper unsets the property', function (string $body, bool $unsets): void {
    $probe = new ReflectionClass(EscapeProbe::class);

    expect((new ConstructionEscape)->unsets($probe, $probe, 'title', escapeProbeBody($body)))->toBe($unsets);
})->with([
    'an unset by name' => ['unset($this->title);', true],
    'one of several unset' => ['unset($this->type, $this->title);', true],
    'an unset in a branch' => ['if (PHP_INT_SIZE > 4) { unset($this->title); }', true],
    'a dynamic name' => ['unset($this->{"ti"."tle"});', true],
    'a followed helper unsetting it' => ['$this->retract();', true],
    'another property unset' => ['unset($this->type);', false],
    'another object\'s member unset' => ['$x = new \\stdClass; unset($x->title);', false],
    'a followed helper that only assigns' => ['$this->named();', false],
    'nothing unset' => ['$this->title = "x";', false],
]);

/*
 * The analyser reads the constructor's class's own helper; a subclass that overrides it runs another body.
 * A private helper is never overridden, so its call runs the parent's whatever the subclass declares.
 */
it('says whether every followed helper runs the body the analyser read', function (string $class, string $body, bool $dispatches): void {
    $declaring = new ReflectionClass(DescribingProbe::class);
    $statements = escapeProbeBody($body);
    $escape = new ConstructionEscape;

    expect($escape->dispatches(new ReflectionClass($class), $declaring, $statements))->toBe($dispatches)
        // An overridden helper is not followed, so it is also where a skip goes unproved.
        ->and($escape->possible(new ReflectionClass($class), $declaring, 'title', $statements))->toBe(! $dispatches);
})->with([
    'the declaring class built as itself' => [DescribingProbe::class, '$this->describe();', true],
    'a subclass overriding the helper' => [SilencedProbe::class, '$this->describe();', false],
    'a private helper the subclass redeclares' => [SilencedProbe::class, '$this->own();', true],
]);
