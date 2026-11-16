<?php

declare(strict_types=1);

use Docuccino\Inference\PhpStan\Analysis\ParentArgument;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried\ProbeLockedException;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried\ProbeQuotaException;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried\ProbeRelayedException;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried\ProbeReorderedException;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried\ProbeReplacedException;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried\ProbeUpstreamException;
use Docuccino\Inference\PhpStan\Tests\Support\Fixtures\Carried\ProbeWrappedException;
use Docuccino\Inference\PhpStan\Tests\Support\ParsedBodies;
use Docuccino\Inference\PhpStan\Throwing\CarriedResponses;
use Illuminate\Http\Exceptions\HttpResponseException;
use PhpParser\PrettyPrinter\Standard;

/**
 * Where the response an `HttpResponseException` subclass carries comes from, read over REAL classes: Laravel
 * sends whatever lands in the framework constructor's `$response`, so that argument is what a throw of the
 * class answers with — a parameter of the subclass's own constructor a construction fills, or an expression
 * one of its constructors writes. What the analyser types it as is the fixture group's
 * ({@see RelayedResponseGoldenTest}); where it lands is decided here.
 */
function carriedLanding(string $fqcn, bool $projectSeesTheFile = true): ?string
{
    $arguments = new ParentArgument(new ParsedBodies, new ProjectFilter($projectSeesTheFile ? ['/'] : [], static fn (string $file): string => $file));
    $landing = $arguments->response($fqcn, CarriedResponses::EXCEPTION);

    return match (true) {
        $landing === null => null,
        isset($landing['expr']) => 'expr '.(new Standard)->prettyPrintExpr($landing['expr']),
        default => 'parameter '.$landing['parameter'],
    };
}

/** @return array<string, array{class-string, string|null}> every carried-response probe, and where its response lands */
function carriedProbeRows(): array
{
    return [
        // Handed on untouched, so each construction says which response it is.
        'the response it is given' => [ProbeRelayedException::class, 'parameter 0'],
        'the response it is given, below a parent handing it on' => [ProbeUpstreamException::class, 'parameter 0'],
        'the response it is given, second' => [ProbeReorderedException::class, 'parameter 1'],
        // Built in a constructor, so every construction carries it.
        'a response it builds' => [ProbeLockedException::class, "expr response()->json(['error' => 'locked'], 423)"],
        'a response it builds, below a parent handing it on' => [ProbeQuotaException::class, "expr response()->json(['error' => 'quota'], 429)"],
        // A parameter moved before it is handed on is no longer what the construction passed.
        'a response rewritten before it is handed on' => [ProbeWrappedException::class, 'expr $response'],
        // Replaced once the parent has stored it: what is sent is not what landed, so nothing is read.
        'a response replaced after the parent stored it' => [ProbeReplacedException::class, null],
    ];
}

it('reads where the response an HttpResponseException subclass carries lands', function (string $fqcn, ?string $landing): void {
    expect(carriedLanding($fqcn))->toBe($landing);
})->with(carriedProbeRows());

it('reads the framework class as carrying its first argument', function (): void {
    expect(carriedLanding(HttpResponseException::class))->toBe('parameter 0');
});

it('has a row for every carried-response probe the fixtures directory ships', function (): void {
    // A dataset only proves the rows it lists; a probe added and named nowhere proves nothing.
    $probes = array_map(
        static fn (string $file): string => 'Docuccino\\Inference\\PhpStan\\Tests\\Support\\Fixtures\\Carried\\'.basename($file, '.php'),
        glob(dirname(__DIR__).'/Support/Fixtures/Carried/*.php') ?: [],
    );
    $rows = array_map(static fn (array $row): string => $row[0], carriedProbeRows());

    expect(count($probes))->toBeGreaterThanOrEqual(7)
        ->and(array_values(array_diff($probes, $rows)))->toBe([]);
});

it('will not read a constructor outside the project', function (): void {
    // The framework's own class is the root, whose constructor needs no reading.
    expect(carriedLanding(ProbeLockedException::class, projectSeesTheFile: false))->toBeNull()
        ->and(carriedLanding(ProbeRelayedException::class, projectSeesTheFile: false))->toBeNull()
        ->and(carriedLanding(HttpResponseException::class, projectSeesTheFile: false))->toBe('parameter 0');
});
