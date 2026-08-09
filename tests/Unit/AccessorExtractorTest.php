<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\AccessorExtractor;
use Docuccino\Inference\PhpStan\Analysis\AccessorKind;
use Docuccino\Inference\PhpStan\Analysis\ParamAccessor;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\FolderProbeEnum;
use PhpParser\Node;

/** `$name` (a parameter variable). */
function aeVar(string $name): Node\Expr\Variable
{
    return new Node\Expr\Variable($name);
}

/** `$var->prop`. */
function aeProp(string $var, string $prop): Node\Expr\PropertyFetch
{
    return new Node\Expr\PropertyFetch(aeVar($var), new Node\Identifier($prop));
}

/** `$var->method(...$args)`. */
function aeCall(string $var, string $method, Node\Arg ...$args): Node\Expr\MethodCall
{
    return new Node\Expr\MethodCall(aeVar($var), new Node\Identifier($method), $args);
}

/** `Enum::Case`. */
function aeCaseConst(string $enum, string $case): Node\Expr\ClassConstFetch
{
    return new Node\Expr\ClassConstFetch(new Node\Name($enum), new Node\Identifier($case));
}

it('classifies parameter accessors: identity, ->value, ->name, ->method()', function (Node\Expr $expr, ?ParamAccessor $expected): void {
    expect(AccessorExtractor::fromExpr($expr, ['problem', 'detail']))->toEqual($expected);
})->with([
    'identity parameter' => [aeVar('detail'), ParamAccessor::identity('detail')],
    '->value' => [aeProp('problem', 'value'), new ParamAccessor('problem', AccessorKind::Value)],
    '->name' => [aeProp('problem', 'name'), new ParamAccessor('problem', AccessorKind::Name)],
    '->status() no-arg method' => [aeCall('problem', 'status'), new ParamAccessor('problem', AccessorKind::Method, 'status')],
    'non-parameter variable' => [aeVar('other'), null],
    'non-parameter property' => [aeProp('other', 'value'), null],
    'unrecognised property (not value/name)' => [aeProp('problem', 'label'), null],
    'method with arguments does not classify' => [aeCall('problem', 'status', new Node\Arg(aeVar('detail'))), null],
    'method on a non-parameter' => [aeCall('other', 'status'), null],
    'not rooted in a parameter' => [aeCaseConst('App\\Problem', 'Forbidden'), null],
]);

it('captures member→accessor provenance from a body array literal (string keys only)', function (): void {
    $item = static fn (?Node\Expr $key, Node\Expr $value): Node\ArrayItem => new Node\ArrayItem($value, $key);

    $array = new Node\Expr\Array_([
        $item(new Node\Scalar\String_('type'), aeProp('problem', 'value')),
        $item(new Node\Scalar\String_('title'), aeCall('problem', 'title')),
        $item(new Node\Scalar\String_('detail'), aeVar('detail')),
        // A member whose value is not rooted in a parameter is omitted.
        $item(new Node\Scalar\String_('trace'), aeVar('trace')),
        // A non-string (computed) key is skipped — not a stable documentable member.
        $item(aeVar('dynamicKey'), aeProp('problem', 'value')),
    ]);

    expect(AccessorExtractor::provenanceFromArray($array, ['problem', 'detail']))->toEqual([
        'type' => new ParamAccessor('problem', AccessorKind::Value),
        'title' => new ParamAccessor('problem', AccessorKind::Method, 'title'),
        'detail' => ParamAccessor::identity('detail'),
    ]);
});

it('re-homes an accessor when the argument is a caller parameter, else declines', function (): void {
    $accessor = new ParamAccessor('inner', AccessorKind::Method, 'status');

    expect(AccessorExtractor::rehome(aeVar('outer'), $accessor, ['outer', 'req']))
        ->toEqual(new ParamAccessor('outer', AccessorKind::Method, 'status'))
        // A non-parameter argument (a literal, a call) does not re-home.
        ->and(AccessorExtractor::rehome(aeVar('nope'), $accessor, ['outer']))->toBeNull()
        ->and(AccessorExtractor::rehome(aeCaseConst('App\\P', 'X'), $accessor, ['outer']))->toBeNull();
});

it('reads a concrete enum case from a Enum::Case constant fetch (via a name resolver)', function (): void {
    $resolve = static fn (Node\Name $n): string => match ($n->toString()) {
        'self' => 'App\\Problem',
        default => $n->toString(),
    };

    // Only a real enum FQCN yields a case (enum_exists gate); ::class and non-enums do not.
    expect(AccessorExtractor::enumCaseFromConstFetch(aeCaseConst(FolderProbeEnum::class, 'Alpha'), $resolve))
        ->toBe(['fqcn' => FolderProbeEnum::class, 'case' => 'Alpha'])
        ->and(AccessorExtractor::enumCaseFromConstFetch(new Node\Expr\ClassConstFetch(new Node\Name(FolderProbeEnum::class), new Node\Identifier('class')), $resolve))->toBeNull()
        ->and(AccessorExtractor::enumCaseFromConstFetch(aeCaseConst('App\\NotAnEnum', 'X'), $resolve))->toBeNull()
        ->and(AccessorExtractor::enumCaseFromConstFetch(aeVar('problem'), $resolve))->toBeNull();
});

it('selects the match ($this) arm naming a case, falling back to default, else null', function (): void {
    $resolve = static fn (Node\Name $n): string => $n->toString() === 'self' ? FolderProbeEnum::class : $n->toString();

    $arm = static fn (?string $case, Node\Expr $body): Node\MatchArm => new Node\MatchArm(
        $case === null ? null : [caseConstResolvedToSelf($case)],
        $body,
    );

    $alpha = new Node\Scalar\Int_(201);
    $beta = new Node\Scalar\Int_(202);
    $fallback = new Node\Scalar\Int_(500);

    $match = new Node\Expr\Match_(aeVar('this'), [
        $arm('Alpha', $alpha),
        $arm('Beta', $beta),
        $arm(null, $fallback),
    ]);

    expect(AccessorExtractor::matchArmBodyForCase($match, FolderProbeEnum::class, 'Alpha', $resolve))->toBe($alpha)
        ->and(AccessorExtractor::matchArmBodyForCase($match, FolderProbeEnum::class, 'Beta', $resolve))->toBe($beta)
        // A case not named by any arm falls to the default.
        ->and(AccessorExtractor::matchArmBodyForCase($match, FolderProbeEnum::class, 'Missing', $resolve))->toBe($fallback);

    // No default and no naming arm → null.
    $noDefault = new Node\Expr\Match_(aeVar('this'), [$arm('Alpha', $alpha)]);
    expect(AccessorExtractor::matchArmBodyForCase($noDefault, FolderProbeEnum::class, 'Beta', $resolve))->toBeNull();

    // A match whose subject is not $this is never folded.
    $notThis = new Node\Expr\Match_(aeVar('other'), [$arm('Alpha', $alpha)]);
    expect(AccessorExtractor::matchArmBodyForCase($notThis, FolderProbeEnum::class, 'Alpha', $resolve))->toBeNull();
});

/** `self::Case` — resolves to FolderProbeEnum via the test resolver. */
function caseConstResolvedToSelf(string $case): Node\Expr\ClassConstFetch
{
    return new Node\Expr\ClassConstFetch(new Node\Name('self'), new Node\Identifier($case));
}
