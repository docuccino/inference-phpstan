<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Tests\Unit;

use Composer\InstalledVersions;
use Docuccino\Inference\PhpStan\Runtime\V2_2\RuntimeAdapter;
use PHPStan\Analyser\Scope;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/*
 * The engine embeds PHPStan, so part of what it depends on is not covered by anyone's promise: the
 * container's service names, the parser router, and the one call that makes a walk's scope safe to keep.
 * That surface moves — `PHPStan\Analyser\Fiber\FiberScope` was deleted in the 2.2.10 PATCH release, and
 * the only thing that noticed was a CI leg resolving a newer analyser than the lockfile, reporting a bare
 * `class.notFound` on a file nobody had touched.
 *
 * These are the guards that make the ENGINE report that instead. Both are stated as pure functions over
 * what the installed analyser declares, so the refusals below are EXECUTED — a guard is worth what its
 * failing case proves, not what its docblock claims.
 */

/**
 * Every PHPStan name the engine's `src/` mentions in CODE that the installed analyser does not have.
 *
 * The general form of the FiberScope break: a compile-time reference to an analyser class that a patch
 * release removed. A name in a STRING is the sanctioned way to probe for something optional and is
 * excluded by the scan itself ({@see referencesIn()}), so everything here is a hard reference the engine
 * would fail to load, and a missing one is drift rather than a style question.
 *
 * @param  list<string>  $references  `relative/path.php: FQCN` strings, as the source scan reports them
 * @return list<string> the same strings, for the references that resolve to nothing
 */
function unresolvableAnalyserReferences(array $references): array
{
    $missing = [];

    foreach ($references as $reference) {
        $name = explode(': ', $reference, 2)[1] ?? '';

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            continue;
        }

        $missing[] = $reference;
    }

    return $missing;
}

/**
 * What has drifted in the analyser's callback-scope surface, or null when it is one of the two shapes
 * {@see RuntimeAdapter::stableScope()} was read against.
 *
 * The two shapes, both inside PHPStan 2.2: up to 2.2.9 a walk hands a callback a fiber scope whose
 * `getType()` suspends, and `toMutatingScope()` is the captured read that survives the fiber; from 2.2.10
 * fibers are gone, a callback scope answers a retained ask itself, and the same call is the identity. So
 * the adapter's single unconditional call is right in both — what it cannot survive is the call going
 * away, which is the first branch here.
 *
 * @param  bool  $declaresStabiliser  `PHPStan\Analyser\Scope` declares `toMutatingScope()`
 * @param  bool  $handsOutFiberScopes  the fiber-scope class the pre-2.2.10 walk used is still present
 * @param  bool  $declaresWalkScope  `PHPStan\Analyser\Scope` declares `toWalkScope()`, the 2.2.10 successor
 */
function analyserScopeDrift(bool $declaresStabiliser, bool $handsOutFiberScopes, bool $declaresWalkScope): ?string
{
    if (! $declaresStabiliser) {
        return 'PHPStan\Analyser\Scope no longer declares toMutatingScope(), the one call '
            .'RuntimeAdapter::stableScope() makes. Since 2.2.10 a callback scope answers a retained ask '
            .'directly, so returning the scope unchanged is probably the whole body now, and toWalkScope() '
            .'is where a snapshot moved — decide that in the adapter rather than widening this guard.';
    }

    if (! $handsOutFiberScopes && ! $declaresWalkScope) {
        return 'This PHPStan neither hands out fiber scopes nor declares Scope::toWalkScope(), so its '
            .'callback scopes are a third design RuntimeAdapter::stableScope() has not been read against. '
            .'Check what a retained scope answers there before widening RuntimeAdapterFactory::SUPPORTED.';
    }

    return null;
}

it('names no analyser class the installed PHPStan has dropped', function (): void {
    // The guard the FiberScope break needed. It reads the whole PHPStan surface the engine names, not the
    // one class that happened to go: the same removal in any other import is the same failure, and this
    // says which file and which name rather than leaving it to whichever CI leg resolves a newer patch.
    $references = importsMatching('inference-phpstan', '/^PHPStan\\\\/');

    // A scan that matches nothing must fail rather than pass: the engine names dozens of analyser
    // classes, so a scan that has stopped seeing them is broken, not clean.
    expect(count($references))->toBeGreaterThan(20);

    expect(unresolvableAnalyserReferences($references))->toBe([]);
});

it('reports an analyser reference that resolves to nothing rather than passing over it', function (): void {
    // The guard above, executed on the state it must refuse. The absent name is a made-up one rather than
    // FiberScope, which still exists on the minors this suite also runs against — a negative case that
    // only holds on some resolutions is not a negative case.
    $references = [
        'Runtime/V2_2/RuntimeAdapter.php: PHPStan\Analyser\Scope',
        'Runtime/V2_2/RuntimeAdapter.php: PHPStan\Analyser\Fiber\ScopeThatNeverWas',
    ];

    expect(unresolvableAnalyserReferences($references))
        ->toBe(['Runtime/V2_2/RuntimeAdapter.php: PHPStan\Analyser\Fiber\ScopeThatNeverWas']);
});

it('runs against a callback-scope surface the adapter was read against', function (): void {
    // The assumption itself, asserted against the analyser this run resolved rather than the one the
    // adapter was written against. `class_exists` on a string is how a class that may be absent is asked
    // about at all — importing it is precisely what broke.
    $drift = analyserScopeDrift(
        method_exists(Scope::class, 'toMutatingScope'),
        class_exists('PHPStan\Analyser\Fiber\FiberScope') || interface_exists('PHPStan\Analyser\Fiber\FiberScope'),
        method_exists(Scope::class, 'toWalkScope'),
    );

    expect($drift)->toBeNull(sprintf(
        'phpstan/phpstan %s: %s',
        InstalledVersions::getPrettyVersion('phpstan/phpstan') ?? 'unknown',
        $drift ?? '',
    ));
});

it('decides drift from the declared surface alone', function (bool $stabiliser, bool $fibers, bool $walkScope, ?string $expected): void {
    // Every combination of the three facts, so the two shapes the adapter handles and the two ways it can
    // be wrong are all stated — including a surface with neither marker, which is the case a guard written
    // as "is it still the old one?" would wave through.
    $drift = analyserScopeDrift($stabiliser, $fibers, $walkScope);

    if ($expected === null) {
        expect($drift)->toBeNull();

        return;
    }

    expect($drift)->toBeString()->toContain($expected);
})->with([
    // The call is there: both eras are fine, and so is a minor carrying both markers at once.
    'fiber era (2.2.0–2.2.9)' => [true, true, false, null],
    'both markers present' => [true, true, true, null],
    'node-callback era (2.2.10+)' => [true, false, true, null],
    // The call is there but the shape is neither — a third design, which nobody has read the adapter against.
    'neither marker' => [true, false, false, 'third design'],
    // The call is gone: nothing else rescues it, whichever markers are present.
    'call gone, fiber era' => [false, true, false, 'no longer declares toMutatingScope()'],
    'call gone, both markers' => [false, true, true, 'no longer declares toMutatingScope()'],
    'call gone, node-callback era' => [false, false, true, 'no longer declares toMutatingScope()'],
    'call gone, neither marker' => [false, false, false, 'no longer declares toMutatingScope()'],
]);

it('asks for the stabilising read through the interface that carries the BC promise', function (): void {
    // Why the adapter calls this on `Scope` and not on a concrete scope class: PHPStan's promise covers
    // the public methods of an `@api` class or interface, and its own ApiMethodCallRule reads exactly that
    // — the DECLARING class's `@api` tag. A concrete class had no such tag, which is the whole reason the
    // old spelling could vanish in a patch. If the tag ever leaves the interface, the call is exposed
    // again and this fails rather than the next patch release doing it for us.
    $interface = new ReflectionClass(Scope::class);

    expect($interface->isInterface())->toBeTrue()
        ->and((string) $interface->getDocComment())->toContain('@api');

    $method = new ReflectionMethod(Scope::class, 'toMutatingScope');

    expect($method->getDeclaringClass()->getName())->toBe(Scope::class)
        ->and($method->isPublic())->toBeTrue()
        ->and($method->isStatic())->toBeFalse()
        ->and($method->getNumberOfRequiredParameters())->toBe(0);

    // And it returns something the adapter can hand back as the `Scope` its contract promises — the
    // degradation this would otherwise hide is a stabilised scope that is no longer a scope.
    $returns = $method->getReturnType();

    expect($returns)->toBeInstanceOf(ReflectionNamedType::class)
        ->and(is_a($returns instanceof ReflectionNamedType ? $returns->getName() : '', Scope::class, true))
        ->toBeTrue();
});
