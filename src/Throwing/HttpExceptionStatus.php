<?php

declare(strict_types=1);

namespace Docuccino\Inference\PhpStan\Throwing;

use Docuccino\Inference\PhpStan\Support\ProjectFilter;
use PhpParser\Node;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The status an `HttpException` subclass pins on itself.
 *
 * `HttpException` takes its status as a constructor argument, so a subclass that fixes one — which is how
 * an application says "this exception IS a 409" — states it in its own `parent::__construct()` call,
 * somewhere no name-keyed table can see. Reading it is what keeps a domain exception off the 500 a lookup
 * miss publishes, and 500 is the one answer that is never merely vague: it names a failure the server does
 * not have, on an endpoint whose only rejection is a 409.
 *
 * Two shapes pin a status, and the same applications carry both: a constant reaching the parent call
 * (`parent::__construct(422, …)`), and a constructor parameter with a constant default forwarded to it
 * (`__construct(array $errors, int $statusCode = 422)`, the static-factory idiom). The second is pinned
 * only where the class controls every construction — a PRIVATE constructor, no trait that could construct
 * out of sight, and no in-class `new self(...)` writing that slot — because a caller free to pass another
 * value makes the default a guess rather than a fact. Where the class pins nothing but forwards a
 * parameter, {@see statusParameter()} names the slot so a construction the build can see — a
 * `throw new X(423, …)`, or the `new self(…)` inside the factory a `throw` names ({@see FactoryStatus}) —
 * can still be folded at its own site.
 *
 * A class only FORWARDS a parameter it hands over untouched. A constructor that writes the variable
 * anywhere in its body (`if ($errors === []) { $statusCode = 400; }`) gives the parent a value neither the
 * caller nor the default names, so it names no slot and pins nothing: what a construction filling that slot
 * would say is not what the response is.
 *
 * Anything else answers null, which means "this class knows a status this build does not" — a different
 * claim from the 500 that means "no HTTP status at all", and the reason the two are not one return value.
 *
 * A class inherits its parent's answer along with its constructor, so the walk is up the hierarchy: a
 * literal the parent pins is the subclass's status too, and the slot the parent forwards is the slot the
 * subclass's own `parent::__construct()` writes into. `HttpException` itself is the base case — argument 0
 * of its constructor IS the status.
 *
 * Only a PROJECT class's constructor is read. Not as a policy but as a measurement: PHPStan hands back an
 * unprimed file with its bodies stripped, so a vendor subclass's `parent::__construct(409, …)` arrives as
 * an empty statement list and the read declines anyway — while asking for it primes that file, growing the
 * analysed set and discarding every recorded walk the replay layer holds. The gate turns a cost with no
 * answer behind it into neither, and it is the same gate {@see FactoryStatus} applies one hop on.
 *
 * And the gate is also why the reads below cost the replay layer nothing, which is worth recording so it is
 * not re-litigated: the adapter primes the application's whole PSR-4 tree at boot, so every project
 * exception class is already in the analysed set and reading one cannot grow it. Measured over one build of
 * the fixture app's 41 throw actions, the analysed-file count is 144 with these reads, without them, and
 * without the hierarchy walk — identical, so no recording is ever discarded. What they do cost is 2 more
 * live file walks out of 15, for exception classes nothing else opened, which is inside run-to-run noise
 * (1.24–1.28s against 1.24–1.29s).
 *
 * @phpstan-type StatusPin array{status: int|null, parameter: int|null, files: list<string>}
 * @phpstan-type AgreedRead array{status: int|null, files: list<string>}
 * @phpstan-type ConstructorSlot array{names: list<string>, default: int|null}
 *
 * @internal
 */
final class HttpExceptionStatus
{
    /** Argument 0 of `HttpException::__construct` is the status. */
    private const STATUS_SLOT = 0;

    /**
     * Resolutions by FQCN. Seeded before the work so a hierarchy that cannot terminate answers rather than
     * recurses, and kept for the whole build so one exception class is read once however many routes throw
     * it.
     *
     * @var array<string, StatusPin>
     */
    private array $cache = [];

    /**
     * {@see agreed()} by FQCN, kept for the same reason and holding its declines, so a class that agrees
     * on nothing is memoised rather than re-read per route.
     *
     * @var array<string, AgreedRead>
     */
    private array $agreed = [];

    public function __construct(
        private readonly ClassBodies $bodies,
        private readonly ProjectFilter $projectFilter,
    ) {}

    public function isHttpException(string $fqcn): bool
    {
        return class_exists($fqcn) && is_a($fqcn, KnownThrowers::HTTP_EXCEPTION, true);
    }

    /** The status the class states for every one of its instances, or null when none folded. */
    public function pinned(string $fqcn): ?int
    {
        return $this->resolve($fqcn)['status'];
    }

    /**
     * The constructor slot whose argument becomes the status, for a class that forwards one rather than
     * pinning it — argument 0 where no class below `HttpException` adds a constructor and its own is what
     * runs. Null when nothing forwards one.
     */
    public function statusParameter(string $fqcn): ?int
    {
        return $this->resolve($fqcn)['parameter'];
    }

    /**
     * The one status every construction the class makes of ITSELF agrees on — a weaker claim than
     * {@see pinned()}, and the only one left where a throw point carries no construction to read: a throw
     * inside a closure the callee runs, one written in a trait and surfacing as a `@throws`, a rethrow.
     * A class with a single factory says its status exactly once, in that factory, and nothing at the
     * throw repeats it.
     *
     * Weaker because a constructor the class does not keep to itself can be called from anywhere, so the
     * constructions here are a subset of the application's rather than all of them — which is why
     * {@see ThrowAnalyzer} asks this only where the throw site itself said nothing, never over a
     * construction it could read. Everything that would make the answer a guess rather than the class's
     * own statement still declines: a constructor that writes the status it forwards, which names no slot
     * to fold into; a class or ancestor this read cannot see all of ({@see constructionsOfSelf()}); and
     * constructions that disagree, where the class genuinely has several.
     *
     * The files are the declarations the read went beyond the hierarchy for — a class constant a
     * construction names ({@see ConstantSource}). The hierarchy's own files come back from
     * {@see filesFor()}, which every caller of this already asks.
     *
     * @return AgreedRead
     */
    public function agreed(string $fqcn): array
    {
        if (isset($this->agreed[$fqcn])) {
            return $this->agreed[$fqcn];
        }

        $this->agreed[$fqcn] = ['status' => null, 'files' => []];

        // A slot is only ever answered for a class; the `class_exists()` is spelled out beside it for the
        // same reason {@see resolve()} spells one out — it is what makes the name one reflection may take.
        $slot = $this->statusParameter($fqcn);
        if ($slot === null || ! class_exists($fqcn)) {
            return $this->agreed[$fqcn];
        }

        $sites = $this->constructionsOfSelf(new ReflectionClass($fqcn));
        if ($sites === null) {
            return $this->agreed[$fqcn];
        }

        $files = [];
        $status = $this->agreedOver($sites, $fqcn, $slot, $files);

        return $this->agreed[$fqcn] = ['status' => $status, 'files' => array_values(array_unique($files))];
    }

    /**
     * The two facts a reader of ONE construction needs about the constructor a `new $fqcn(...)` binds to:
     * its parameter names, so an argument written by name lands in the position it fills, and the constant
     * default of `$slot`, which is the value a construction that leaves that slot empty passes. The default
     * is read here rather than restated elsewhere, so one rule says what counts as one.
     *
     * @return ConstructorSlot
     */
    public function constructorSlot(string $fqcn, int $slot): array
    {
        $constructor = class_exists($fqcn) ? (new ReflectionClass($fqcn))->getConstructor() : null;

        return ['names' => self::parameterNames($constructor), 'default' => $this->constantDefault($constructor, $slot)];
    }

    /**
     * Files whose contents were read to answer, for the analysis's dependency set: an exception class that
     * changes the status it sets must rebuild every route that throws it, and a warm build that missed it
     * would publish a status a cold one does not. The whole hierarchy is recorded, not just the class that
     * happens to declare the constructor today — adding one lower down changes the answer.
     *
     * @return list<string>
     */
    public function filesFor(string $fqcn): array
    {
        return $this->resolve($fqcn)['files'];
    }

    /**
     * @return StatusPin
     */
    private function resolve(string $fqcn): array
    {
        if (isset($this->cache[$fqcn])) {
            return $this->cache[$fqcn];
        }

        $this->cache[$fqcn] = ['status' => null, 'parameter' => null, 'files' => []];

        // Spelled out rather than asked of `isHttpException()`, which answers the same question: the
        // `class_exists()` is what makes the name a class the reflection below may be handed.
        if (! class_exists($fqcn) || ! is_a($fqcn, KnownThrowers::HTTP_EXCEPTION, true)) {
            return $this->cache[$fqcn];
        }

        $read = $this->forClass(new ReflectionClass($fqcn));
        $read['files'] = array_values(array_unique($read['files']));

        return $this->cache[$fqcn] = $read;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return StatusPin
     */
    private function forClass(ReflectionClass $class): array
    {
        $file = $class->getFileName();
        $files = $file === false ? [] : [$file];

        if ($class->getName() === KnownThrowers::HTTP_EXCEPTION) {
            return ['status' => null, 'parameter' => self::STATUS_SLOT, 'files' => $files];
        }

        $parent = $class->getParentClass();
        if ($parent === false) {
            return ['status' => null, 'parameter' => null, 'files' => $files];
        }

        $inherited = $this->resolve($parent->getName());
        $files = [...$files, ...$inherited['files']];

        $constructor = $class->getConstructor();

        // No constructor of its own, so the parent's is what runs — and its answer is this class's.
        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $class->getName()) {
            return ['status' => $inherited['status'], 'parameter' => $inherited['parameter'], 'files' => $files];
        }

        return $this->readConstructor($class, $constructor, $parent, $inherited, $files);
    }

    /**
     * What one class's own constructor does with the status the parent's takes.
     *
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionClass<object>  $parent
     * @param  StatusPin  $inherited
     * @param  list<string>  $files
     * @return StatusPin
     */
    private function readConstructor(
        ReflectionClass $class,
        ReflectionMethod $constructor,
        ReflectionClass $parent,
        array $inherited,
        array $files,
    ): array {
        $file = $class->getFileName();
        if ($file === false || ! $this->projectFilter->isProjectFile($file)) {
            return self::nothing($files);
        }

        $body = $this->bodies->methods($file, $class->getName())['__construct'] ?? null;
        if ($body === null) {
            return self::nothing($files);
        }

        $call = StatusForwarding::parentCall($body);
        if ($call === null) {
            return self::nothing($files);
        }

        // A literal the parent pins is one no subclass can move: it reaches the parent's own
        // `parent::__construct()` call, and this constructor only chooses the message beside it.
        if ($inherited['status'] !== null) {
            return ['status' => $inherited['status'], 'parameter' => null, 'files' => $files];
        }

        $slot = $inherited['parameter'];
        if ($slot === null) {
            return self::nothing($files);
        }

        $argument = StatusForwarding::argumentAt($call, $slot, self::parameterNames($parent->getConstructor()));
        if ($argument === null) {
            return self::nothing($files);
        }

        // The declaration a `parent::__construct(HttpStatus::CONFLICT, …)` reads decides this class's
        // status, so it joins the set whether or not the fold went on to succeed.
        $files = [...$files, ...ConstantSource::files($argument, $class->getName())];

        $folded = HttpStatusCode::folded($this->bodies->foldInt($file, $argument, $call));
        if ($folded !== null) {
            return ['status' => $folded, 'parameter' => null, 'files' => $files];
        }

        if (! $argument instanceof Node\Expr\Variable || ! is_string($argument->name)) {
            return self::nothing($files);
        }

        // A body that WRITES the variable it forwards hands the parent a value neither the caller nor the
        // default names — `if ($errors === []) { $statusCode = 400; }` really builds a 400 — so this class
        // forwards no slot at all, and a `throw new X(409, …)` cannot be read off one either.
        if (StatusForwarding::reassigns($body, $argument->name)) {
            return self::nothing($files);
        }

        $index = array_search($argument->name, self::parameterNames($constructor), true);
        if (! is_int($index)) {
            return self::nothing($files);
        }

        // …and the declaration behind the DEFAULT of that slot, which is the value a construction leaving
        // it empty passes. Named through reflection rather than evaluated: `getDefaultValueConstantName()`
        // reads the initialiser's name off the declaration, where `getDefaultValue()` would run it.
        $default = ConstantSource::fileForName(self::defaultConstantName($constructor, $index) ?? '', $class->getName());
        if ($default !== null) {
            $files[] = $default;
        }

        $status = $this->forwardedDefault($class, $constructor, $index, $file, $files);

        return ['status' => $status, 'parameter' => $index, 'files' => $files];
    }

    /**
     * A class that states nothing, answering with the files the read had already gone through to find
     * that out. Spelled as a function of the live set rather than captured once at the top of a body: a
     * declaration a fold READ decided this class's status whether or not the fold went on to succeed, and
     * a decline handing back a stale copy of the set leaves that file off the fragment's dependency list.
     *
     * @param  list<string>  $files
     * @return StatusPin
     */
    private static function nothing(array $files): array
    {
        return ['status' => null, 'parameter' => null, 'files' => $files];
    }

    /**
     * The status a class with a PRIVATE constructor states for every one of its instances: every
     * construction is written in this class, so the one they agree on is the one the class has. A slot they
     * all leave empty takes the constructor's default, one they write takes its own folded literal, and
     * either way it is the same value every time or the class states none.
     *
     * That the constructor forwards the parameter untouched is already settled by the caller, which
     * declines outright for a body that writes it. A class that uses a trait declines at one remove: a
     * trait's methods are written in another file, so a `new self(...)` there is one this read never sees.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<string>  $files
     */
    private function forwardedDefault(
        ReflectionClass $class,
        ReflectionMethod $constructor,
        int $index,
        string $file,
        array &$files,
    ): ?int {
        if (! $constructor->isPrivate() || $class->getTraitNames() !== []) {
            return null;
        }

        // The class's own declared code is the whole set here, and no ancestor is walked: a private
        // constructor is out of scope from a base, so a `new static(…)` in one would not even compile.
        return $this->agreedOver(
            $this->constructionsIn($class->getName(), $class->getName(), $file),
            $class->getName(),
            $index,
            $files,
        );
    }

    /**
     * The one status a set of the class's own constructions folds to, each at the site it is written.
     * `$files` collects the declarations the folds read, for the dependency set.
     *
     * @param  list<ConstructionSite>  $sites
     * @param  list<string>  $files
     */
    private function agreedOver(array $sites, string $class, int $slot, array &$files): ?int
    {
        return ConstructionStatus::agreedIn(
            $sites,
            $slot,
            $this->constructorSlot($class, $slot),
            function (Node\Expr $argument, ConstructionSite $site) use (&$files): ?int {
                $files = [...$files, ...ConstantSource::files($argument, $site->declaringClass)];

                return $this->bodies->foldInt($site->file, $argument, $site->construction);
            },
        );
    }

    /**
     * Every construction of `$class` this build can see, each with the file it is written in — or null
     * where the set could not be completed, which is a different answer from an empty one.
     *
     * A construction the class makes of ITSELF is one written in its own declared code OR in a class it
     * inherits from: `new static(…)` in a base builds the subclass as surely as `new self(…)` in the
     * subclass does, and reading only the subclass's own answers a status for a class that has two. So
     * the walk is the hierarchy, up to `HttpException`, whose own constructor takes the status and builds
     * no subclass. Read them all or read none: an ancestor written in a file this build cannot open — a
     * class outside the project, whose bodies PHPStan strips — or one using a TRAIT, whose methods live in
     * a file this read never opens, leaves a construction unseen, and a partial set is a status the class
     * may not have.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<ConstructionSite>|null
     */
    private function constructionsOfSelf(ReflectionClass $class): ?array
    {
        $target = $class->getName();
        $constructions = [];

        for (
            $declaring = $class;
            $declaring !== false && $declaring->getName() !== KnownThrowers::HTTP_EXCEPTION;
            $declaring = $declaring->getParentClass()
        ) {
            $file = $declaring->getFileName();
            if ($file === false
                || $declaring->getTraitNames() !== []
                || ! $this->projectFilter->isProjectFile($file)
            ) {
                return null;
            }

            foreach ($this->constructionsIn($target, $declaring->getName(), $file) as $construction) {
                $constructions[] = $construction;
            }
        }

        return $constructions;
    }

    /**
     * Every `new` that builds `$target` in the methods `$declaring` writes in `$file` — its constructor
     * included, which a private constructor is reachable from and from nowhere else. Each one carries
     * where it is written ({@see ConstructionSite}).
     *
     * @return list<ConstructionSite>
     */
    private function constructionsIn(string $target, string $declaring, string $file): array
    {
        $sites = [];
        foreach ($this->bodies->methods($file, $declaring) as $statements) {
            foreach (StatusForwarding::constructionsOf($statements, $target, $declaring) as $construction) {
                $sites[] = new ConstructionSite($construction, $file, $declaring);
            }
        }

        return $sites;
    }

    /**
     * The constant integer default of one parameter, which is what a call leaving that slot empty passes.
     * Asked of the declaration through {@see ClassBodies::intDefault()} rather than of reflection, which
     * would EXECUTE the initialiser — PHP has allowed `new` in one since 8.1.
     *
     * Project files only, for the reason the class docblock gives: reading a vendor declaration costs the
     * file's analysis, and `HttpException` and every subclass of it Symfony ships take their status with no
     * default at all, so there is nothing behind the cost.
     */
    private function constantDefault(?ReflectionMethod $method, int $index): ?int
    {
        $file = $method?->getFileName();
        if ($method === null || $file === false || $file === null || ! $this->projectFilter->isProjectFile($file)) {
            return null;
        }

        return HttpStatusCode::folded(
            $this->bodies->intDefault($file, $method->getDeclaringClass()->getName(), $method->getName(), $index),
        );
    }

    /** The constant a parameter's default NAMES, where it names one — never the value it would evaluate to. */
    private static function defaultConstantName(ReflectionMethod $method, int $index): ?string
    {
        $parameter = $method->getParameters()[$index] ?? null;

        return $parameter?->isDefaultValueAvailable() === true && $parameter->isDefaultValueConstant()
            ? $parameter->getDefaultValueConstantName()
            : null;
    }

    /**
     * @return list<string>
     */
    private static function parameterNames(?ReflectionMethod $method): array
    {
        return $method === null ? [] : array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        );
    }
}
