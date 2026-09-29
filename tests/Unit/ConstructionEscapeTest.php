<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Metadata\ConstructionEscape;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\EscapeProbe;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Initialisation\PrivateProbe;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/*
 * A constructor path that skips `title` proves the key may be left out only where nothing assigns it that
 * the analyser does not see. It follows one level of `$this->method()` into a method the class declares and
 * nothing else, so every other way a constructor hands `$this` on leaves the skip unproved, and the snippets
 * that hand nothing on leave it standing. Each snippet is a constructor body of `EscapeProbe`.
 */
it('says whether anything unfollowed may assign the property', function (string $body, bool $escapes): void {
    $parsed = (new ParserFactory)->createForHostVersion()->parse('<?php namespace Docuccino\\Inference\\PhpStan\\Tests\\Support\\Fixtures\\Initialisation; '.$body);
    $statements = (new NodeTraverser(new NameResolver))->traverse($parsed ?? []);

    expect((new ConstructionEscape)->possible(new ReflectionClass(EscapeProbe::class), 'title', $statements))->toBe($escapes);
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
    expect((new ConstructionEscape)->possible(new ReflectionClass(PrivateProbe::class), 'title', []))->toBeTrue();
});
