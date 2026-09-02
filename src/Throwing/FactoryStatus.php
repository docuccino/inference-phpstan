<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node;
use ReflectionClass;
use ReflectionMethod;

/**
 * The status a static factory builds its exception with, for the class that states none of its own.
 *
 * A class whose factories each choose a status genuinely has no one status — `duplicatePath()` is a 422 and
 * `cannotDeleteRoot()` a 403 on the same class — so {@see HttpExceptionStatus} is right to answer null for
 * it, and the answer is one hop away: the `throw` names the factory, and the factory names the status. One
 * hop and no further; this is not constant propagation, it is reading the `new` the named factory makes.
 *
 * Every way the read could publish a status the code does not pass is a decline: a factory whose file is not
 * the project's, a body that builds the class more than once and folds to two different statuses, a slot
 * nothing can be said about ({@see ConstructionStatus}), and a base's factory whose `new self(…)` builds the
 * base rather than this class.
 *
 * @phpstan-type FactoryRead array{status: int|null, files: list<string>}
 *
 * @internal
 */
final class FactoryStatus
{
    /**
     * Reads by `Class::factory`, kept for the whole build so one factory thrown by forty routes is read
     * once — and written before each early return as well as after the work, so a factory that answers
     * nothing is memoised as a decline rather than re-read per route.
     *
     * @var array<string, FactoryRead>
     */
    private array $cache = [];

    public function __construct(
        private readonly HttpExceptionStatus $statuses,
        private readonly ClassBodies $bodies,
        private readonly ProjectFilter $projectFilter,
    ) {}

    /**
     * What `$fqcn::$method()` builds the exception with. The files are the ones the answer depended on,
     * reported whether or not one folded — the read has to rebuild when the factory changes either way.
     *
     * @return FactoryRead
     */
    public function forFactory(string $fqcn, string $method): array
    {
        $key = $fqcn.'::'.$method;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $this->cache[$key] = ['status' => null, 'files' => []];

        // The slot the class forwards its status through. Null where nothing of the class's reaches
        // `HttpException` at all, and null where its own `parent::__construct()` pins a LITERAL — which no
        // factory can move, so there is nothing here to read. A pin that came from a private constructor's
        // DEFAULT still names its slot, so this read does run for one, and agrees with it: a factory that
        // leaves the slot empty passes that same default.
        $slot = $this->statuses->statusParameter($fqcn);
        if ($slot === null) {
            return $this->cache[$key];
        }

        $factory = self::factory($fqcn, $method);
        if ($factory === null) {
            return $this->cache[$key];
        }

        $file = $factory->getFileName();
        if ($file === false) {
            return $this->cache[$key];
        }

        $declaring = $factory->getDeclaringClass()->getName();
        $body = $this->projectFilter->isProjectFile($file)
            ? ($this->bodies->methods($file, $declaring)[$method] ?? null)
            : null;

        $files = [$file];
        $status = $body === null ? null : $this->fromBody($fqcn, $declaring, $file, $body, $slot, $files);

        return $this->cache[$key] = ['status' => $status, 'files' => array_values(array_unique($files))];
    }

    /**
     * The one status every construction in the body agrees on — the same rule the throw site reads a
     * `throw new X(…)` by, so one hop apart cannot mean two answers. `$declaring` is the class the body is
     * written in, which decides what its relative names build ({@see StatusForwarding::constructionsOf()}).
     * `$files` collects the declarations the folds read, for the dependency set.
     *
     * @param  array<array-key, Node\Stmt>  $body
     * @param  list<string>  $files
     */
    private function fromBody(string $fqcn, string $declaring, string $file, array $body, int $slot, array &$files): ?int
    {
        $sites = array_map(
            static fn (Node\Expr\New_ $construction): ConstructionSite => new ConstructionSite($construction, $file, $declaring),
            StatusForwarding::constructionsOf($body, $fqcn, $declaring),
        );

        return ConstructionStatus::agreedIn(
            $sites,
            $slot,
            $this->statuses->constructorSlot($fqcn, $slot),
            function (Node\Expr $argument, ConstructionSite $site) use (&$files): ?int {
                $files = [...$files, ...ConstantSource::files($argument, $site->declaringClass)];

                return $this->bodies->foldInt($site->file, $argument, $site->construction);
            },
        );
    }

    /**
     * The static factory a `throw X::y()` names, or null for anything else it could name — an instance
     * method, or a name the class does not have.
     *
     * A factory a BASE declares is one of them: `X::unavailable()` is how the class is built whoever wrote
     * the line, and the same rule {@see HttpExceptionStatus::agreed()} reads a hierarchy by says a base's
     * `new static(…)` builds this class. What the base's `new self(…)` builds is the base, which is why
     * the declaring class travels with the body rather than being assumed to be this one.
     */
    private static function factory(string $fqcn, string $method): ?ReflectionMethod
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        $class = new ReflectionClass($fqcn);
        if (! $class->hasMethod($method)) {
            return null;
        }

        $factory = $class->getMethod($method);

        return $factory->isStatic() ? $factory : null;
    }
}
