<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Core\Inference\MethodDeclaration;
use Docuccino\Inference\PhpStan\Support\ParsedFiles;
use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use Docuccino\Inference\PhpStan\Trace\CalleeResolver;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionException;
use ReflectionMethod;

/**
 * The catches a callee writes around each place it runs a callable it was handed, read off the callee's
 * source with {@see EnclosingCatches}' grammar — a catch that hands what it caught on takes nothing. A
 * closure's throws reach the caller only through one of those places, so a class every one of them takes
 * never does. No sites wherever the places cannot all be named: the parameter is read any other way than
 * called — passed on, stored, captured — or never called at all.
 *
 * Only the application's own callees are read: vendor is a terminal for every other read of what a call
 * throws, so a package's catches are no more this reader's to weigh than its throws are. The exception is
 * the framework functions in {@see CATCHES_BY_CONTRACT}, read by that contract: what their catch hands on
 * (`report($e)`, the `$rescue` default) is what the contract describes, and keeps what it caught.
 *
 * @internal
 */
final class CalleeCatches
{
    /**
     * Framework functions whose contract is to catch what the callable they run throws. Their body is still
     * read — the installed one — so what a catch takes is the version the application resolved.
     */
    public const CATCHES_BY_CONTRACT = ['rescue'];

    /** Reads of the callable that name no place it runs: by position, or every local at once. */
    private const OPAQUE_READS = ['func_get_args', 'func_get_arg', 'get_defined_vars', 'compact', 'extract'];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly CalleeResolver $calleeResolver,
        private readonly ProjectFilter $appFilter,
        private readonly ParsedFiles $files = new ParsedFiles,
    ) {}

    /**
     * The catch classes around each place the callee runs the argument at `$position`, one list per place
     * (null where they cannot all be named), and the file the callee is written in wherever it resolved —
     * which decided the answer, null or not.
     *
     * @return array{sites: list<list<string>>|null, file: string|null}
     */
    public function around(Node\Expr\CallLike $call, int $position, Scope $scope): array
    {
        $argument = $call->getArgs()[$position] ?? null;
        $declaration = $argument === null || $argument->unpack ? null : $this->declaration($call, $scope);
        if ($argument === null || $declaration === null) {
            return ['sites' => null, 'file' => null];
        }
        [$function, $file, $byContract] = $declaration;

        $parameter = $function === null ? null : self::parameter($function, $argument, $position);

        return [
            'sites' => $function === null || $parameter === null ? null : self::sites($function->getStmts() ?? [], $parameter, $byContract),
            'file' => $file,
        ];
    }

    /**
     * The callee's declaration where this may read it — null where it cannot be found in the file — the file
     * it is written in, and whether it is read by contract.
     *
     * @return array{Node\FunctionLike|null, string, bool}|null
     */
    private function declaration(Node\Expr\CallLike $call, Scope $scope): ?array
    {
        if ($call instanceof Node\Expr\FuncCall) {
            if (! $call->name instanceof Node\Name || ! $this->reflectionProvider->hasFunction($call->name, $scope)) {
                return null;
            }

            $function = $this->reflectionProvider->getFunction($call->name, $scope);
            $file = $function->getFileName();
            $byContract = in_array(strtolower($function->getName()), self::CATCHES_BY_CONTRACT, true);
            if ($file === null || (! $this->appFilter->isProjectFile($file) && ! $byContract)) {
                return null;
            }

            $found = (new NodeFinder)->find($this->files->statements($file), static fn (Node $node): bool => $node instanceof Node\Stmt\Function_
                && strcasecmp($node->namespacedName?->toString() ?? '', $function->getName()) === 0);

            return [count($found) === 1 && $found[0] instanceof Node\Stmt\Function_ ? $found[0] : null, $file, $byContract];
        }

        $callee = $this->calleeResolver->resolve($call, $scope);
        if ($callee === null || ! $this->appFilter->isProjectFile($callee->writtenIn())) {
            return null;
        }

        // A trait's body is written in the trait's file, under the trait's name rather than the using class's.
        $file = $callee->writtenIn();
        try {
            $method = MethodDeclaration::in($this->files->statements($file), new ReflectionMethod($callee->class, $callee->method));
        } catch (ReflectionException) {
            $method = null;
        }

        return [$method, $file, false];
    }

    /** The parameter an argument binds, where it is one plain variable a call could name. */
    public static function parameter(Node\FunctionLike $function, Node\Arg $argument, int $position): ?string
    {
        $parameters = $function->getParams();
        $bound = null;
        if ($argument->name !== null) {
            foreach ($parameters as $parameter) {
                if ($parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $argument->name->toString()) {
                    $bound = $parameter;
                }
            }
        } else {
            $bound = $parameters[$position] ?? null;
        }

        if ($bound === null || $bound->variadic || $bound->byRef
            || ! $bound->var instanceof Node\Expr\Variable || ! is_string($bound->var->name)
        ) {
            return null;
        }

        return $bound->var->name;
    }

    /**
     * The catches around every `$parameter(…)`, or null where the parameter is read any other way, or
     * called nowhere.
     *
     * @param  array<Node\Stmt>  $statements
     * @return list<list<string>>|null
     */
    public static function sites(array $statements, string $parameter, bool $byContract = false): ?array
    {
        $calls = [];
        $callees = [];
        $opaque = false;
        foreach ((new NodeFinder)->find($statements, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\Variable) as $node) {
            if ($node instanceof Node\Expr\FuncCall) {
                if ($node->name instanceof Node\Expr\Variable && $node->name->name === $parameter) {
                    $calls[] = $node;
                    $callees[spl_object_id($node->name)] = true;
                } elseif ($node->name instanceof Node\Name && in_array($node->name->toLowerString(), self::OPAQUE_READS, true)) {
                    $opaque = true;
                }

                continue;
            }

            if (! $node instanceof Node\Expr\Variable) {
                continue;
            }

            if (! is_string($node->name) || ($node->name === $parameter && ! isset($callees[spl_object_id($node)]))) {
                $opaque = true;
            }
        }

        if ($opaque || $calls === []) {
            return null;
        }

        $sites = [];
        foreach ($calls as $call) {
            $sites[] = array_map(
                static fn (Node\Name $name): string => $name->toString(),
                EnclosingCatches::around($statements, $call->getStartFilePos(), $byContract ? static fn (): bool => true : null),
            );
        }

        return $sites;
    }
}
