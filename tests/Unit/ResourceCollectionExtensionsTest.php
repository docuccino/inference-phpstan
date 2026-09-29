<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Extensions\ResourceCollectionReturnTypeExtension;
use Docuccino\Inference\PhpStan\Extensions\ResourceCollectionTransformReturnTypeExtension;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/*
 * The two extensions typing a resource list as the collection its `newCollection()` builds answer only for
 * a resource they can name; everywhere else they leave the bundled stub's answer standing. The mechanics
 * only — reading the override needs the analyser's class reflection, and the fixture group's
 * ResourceCollectionOverrideTest is that half.
 */
it('extends the classes the framework declares the two calls on', function (): void {
    $provider = $this->createStub(ReflectionProvider::class);

    expect((new ResourceCollectionReturnTypeExtension($provider))->getClass())->toBe('Illuminate\\Http\\Resources\\Json\\JsonResource')
        ->and((new ResourceCollectionTransformReturnTypeExtension($provider))->getClass())->toBe('Illuminate\\Contracts\\Support\\Arrayable');
});

it('leaves Resource::collection() to the stub where the called class cannot be named or reflected', function (Node\Expr|Node\Name $class): void {
    $provider = $this->createStub(ReflectionProvider::class);
    $provider->method('hasClass')->willReturn(false);
    $scope = $this->createStub(Scope::class);
    $scope->method('resolveName')->willReturn('App\\Nowhere\\Resource');

    $type = (new ResourceCollectionReturnTypeExtension($provider))->getTypeFromStaticMethodCall(
        $this->createStub(MethodReflection::class),
        new Node\Expr\StaticCall($class, new Node\Identifier('collection')),
        $scope,
    );

    expect($type)->toBeNull();
})->with([
    'a class chosen at runtime' => [new Node\Expr\Variable('resource')],
    'a class the analyser does not know' => [new Node\Name('Resource')],
]);

it('leaves toResourceCollection() to the stub unless it names exactly one class the analyser knows', function (array $args, ?Type $argument): void {
    $provider = $this->createStub(ReflectionProvider::class);
    $provider->method('hasClass')->willReturn(false);
    $scope = $this->createStub(Scope::class);
    $scope->method('getType')->willReturnCallback(static fn (): Type => $argument ?? throw new RuntimeException('the argument was not to be read'));

    $type = (new ResourceCollectionTransformReturnTypeExtension($provider))->getTypeFromMethodCall(
        $this->createStub(MethodReflection::class),
        new Node\Expr\MethodCall(new Node\Expr\Variable('items'), new Node\Identifier('toResourceCollection'), $args),
        $scope,
    );

    expect($type)->toBeNull();
})->with([
    // The argument-less form guesses the resource from the model's name at runtime.
    'no argument' => [[], null],
    'a spread' => [[new Node\Arg(new Node\Expr\Variable('args'), unpack: true)], null],
    'a class chosen at runtime' => [[new Node\Arg(new Node\Expr\Variable('class'))], new StringType],
    'one of two classes' => [[new Node\Arg(new Node\Expr\Variable('class'))], TypeCombinator::union(new ConstantStringType('App\\A'), new ConstantStringType('App\\B'))],
    'a class the analyser does not know' => [[new Node\Arg(new Node\Scalar\String_('App\\Nowhere\\Resource'))], new ConstantStringType('App\\Nowhere\\Resource')],
]);
